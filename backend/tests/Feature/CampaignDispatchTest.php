<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\BusinessType;
use App\Models\Campaign;
use App\Models\CampaignMessage;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CampaignDispatchTest extends TestCase
{
    use RefreshDatabase;

    private BusinessType $businessType;

    private Business $business;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        // Campaign send/loop routes live behind the feature:crm_enabled gate.
        config(['features.crm_enabled' => true]);

        $this->businessType = BusinessType::create([
            'slug' => 'supermarket',
            'name_en' => 'Supermarket',
            'name_ar' => 'سوبر ماركت',
            'allowed_modules' => ['crm'],
            'default_settings' => [],
        ]);

        $this->business = Business::create([
            'id' => (string) Str::uuid(),
            'business_type_id' => $this->businessType->id,
            'name' => 'Test Grocery',
            'slug' => 'test-grocery',
            'status' => 'active',
        ]);

        $this->user = User::create([
            'name' => 'Admin',
            'email' => 'admin@test.com',
            'username' => 'admin',
            'password' => 'password',
            'business_id' => $this->business->id,
            'role' => 'admin',
        ]);

        Sanctum::actingAs($this->user);
    }

    private function createCustomers(array $overrides = []): array
    {
        return array_map(fn ($i, $overrides) => Customer::create(array_merge([
            'business_id' => $this->business->id,
            'name' => "Customer {$i}",
            'phone' => "+96278123456{$i}",
            'total_spend' => 0,
            'total_visits' => 0,
        ], $overrides)), range(1, count($overrides)), array_values($overrides));
    }

    private function createCampaign(array $overrides = []): Campaign
    {
        return Campaign::create(array_merge([
            'business_id' => $this->business->id,
            'name' => 'Test Campaign',
            'segment_type' => 'all',
            'channel' => 'sms',
            'message_template' => 'Hello {name}!',
            'status' => 'draft',
            'recipients_count' => 0,
            'metadata' => [],
        ], $overrides));
    }

    // ── Store & count ─────────────────────────────────────────────

    public function test_store_creates_draft_and_counts_recipients(): void
    {
        $this->createCustomers([
            ['total_spend' => 0, 'total_visits' => 1, 'last_visit_date' => now()->subDays(60)],
            ['total_spend' => 0, 'total_visits' => 0],
        ]);

        $res = $this->postJson('/api/v1/campaigns', [
            'name' => 'Lost Win-Back',
            'segment_type' => 'lost',
            'channel' => 'sms',
            'message_template' => 'We miss you, {name}!',
        ]);

        $res->assertCreated();
        $data = $res->json();
        $this->assertEquals('draft', $data['status']);
        // Lost = total_visits > 0 + last_visit < 30d => 1 match
        $this->assertEquals(1, $data['recipients_count']);
    }

    public function test_store_counts_vip_segment(): void
    {
        $this->createCustomers([
            ['is_vip' => true, 'total_spend' => 1000, 'total_visits' => 1],
            ['is_vip' => false, 'total_spend' => 10, 'total_visits' => 1],
        ]);

        $res = $this->postJson('/api/v1/campaigns', [
            'name' => 'VIP',
            'segment_type' => 'vip',
            'channel' => 'whatsapp',
            'message_template' => 'VIP offer {name}',
        ]);

        $res->assertCreated();
        $this->assertEquals(1, $res->json('recipients_count'));
    }

    public function test_store_counts_all_segment(): void
    {
        $this->createCustomers([['total_spend' => 0], ['total_spend' => 0]]);

        $res = $this->postJson('/api/v1/campaigns', [
            'name' => 'All',
            'segment_type' => 'all',
            'channel' => 'sms',
            'message_template' => 'Hi {name}',
        ]);

        $res->assertCreated();
        $this->assertEquals(2, $res->json('recipients_count'));
    }

    public function test_store_accepts_tier_segment(): void
    {
        $c = $this->createCustomers([['total_spend' => 0]]);
        // The customer gets a loyalty card on created event (tier=bronze)
        $res = $this->postJson('/api/v1/campaigns', [
            'name' => 'Bronze Tier',
            'segment_type' => 'tier',
            'tier' => 'bronze',
            'channel' => 'sms',
            'message_template' => 'Hey {name}',
        ]);

        $res->assertCreated();
        $this->assertEquals(1, $res->json('recipients_count'));
    }

    public function test_store_rejects_invalid_segment(): void
    {
        $this->postJson('/api/v1/campaigns', [
            'name' => 'Bad',
            'segment_type' => 'deleted',
            'channel' => 'sms',
            'message_template' => 'Hi',
        ])->assertStatus(422);
    }

    // ── Send dispatch ─────────────────────────────────────────────

    public function test_send_dispatches_job_and_processes_inline(): void
    {
        $this->createCustomers([['total_spend' => 0], ['total_spend' => 0]]);

        $res = $this->postJson('/api/v1/campaigns', [
            'name' => 'Send All',
            'segment_type' => 'all',
            'channel' => 'sms',
            'message_template' => 'Hi {name}',
        ]);

        $campaign = Campaign::find($res->json('id'));

        // The endpoint uses dispatchSync so it runs inline even with Queue::fake
        $sendRes = $this->postJson("/api/v1/campaigns/{$campaign->id}/send");
        $sendRes->assertOk();

        $campaign->refresh();
        $this->assertContains($campaign->status, ['completed', 'failed']);
        $this->assertNotNull($campaign->started_at);
        $this->assertNotNull($campaign->completed_at);
        $this->assertEquals(2, $campaign->sent_count);
    }

    public function test_send_cannot_run_twice(): void
    {
        $this->createCustomers([['total_spend' => 0]]);

        $res = $this->postJson('/api/v1/campaigns', [
            'name' => 'Already Sent',
            'segment_type' => 'all',
            'channel' => 'sms',
            'message_template' => 'Hi {name}',
        ]);

        $campaign = Campaign::find($res->json('id'));

        $this->postJson("/api/v1/campaigns/{$campaign->id}/send")->assertOk();
        $campaign->refresh();
        $this->assertEquals('completed', $campaign->status);

        // Second send should fail
        $this->postJson("/api/v1/campaigns/{$campaign->id}/send")->assertStatus(422);
    }

    public function test_send_can_resend_after_failed(): void
    {
        $customer = $this->createCustomers([
            ['total_spend' => 0, 'phone' => 'invalid-phone'],
        ]);

        $res = $this->postJson('/api/v1/campaigns', [
            'name' => 'Fail Then Retry',
            'segment_type' => 'all',
            'channel' => 'sms',
            'message_template' => 'Hi {name}',
        ]);

        $campaign = Campaign::find($res->json('id'));

        // First send — all fail (invalid phone)
        $this->postJson("/api/v1/campaigns/{$campaign->id}/send")->assertOk();
        $campaign->refresh();
        $this->assertEquals('failed', $campaign->status);
        $this->assertEquals(1, $campaign->failed_count);

        // Update customer phone, resend
        $customer[0]->update(['phone' => '+962789999999']);
        $this->postJson("/api/v1/campaigns/{$campaign->id}/send")->assertOk();
        $campaign->refresh();
        $this->assertEquals('completed', $campaign->status);
        $this->assertEquals(1, $campaign->sent_count);
    }

    public function test_send_empty_segment_marks_completed(): void
    {
        $res = $this->postJson('/api/v1/campaigns', [
            'name' => 'Empty',
            'segment_type' => 'lost',
            'channel' => 'sms',
            'message_template' => 'Hi {name}',
        ]);

        $campaign = Campaign::find($res->json('id'));
        $this->assertEquals(0, $campaign->recipients_count);

        $this->postJson("/api/v1/campaigns/{$campaign->id}/send")->assertOk();
        $campaign->refresh();
        $this->assertEquals('completed', $campaign->status);
        $this->assertEquals(0, $campaign->sent_count);
    }

    public function test_send_vip_only_sends_to_vip(): void
    {
        $this->createCustomers([
            ['total_spend' => 0, 'is_vip' => true, 'name' => 'VIP One'],
            ['total_spend' => 0, 'is_vip' => false, 'name' => 'Regular'],
        ]);

        $res = $this->postJson('/api/v1/campaigns', [
            'name' => 'VIP Only',
            'segment_type' => 'vip',
            'channel' => 'sms',
            'message_template' => 'Hello {name}',
        ]);

        $campaign = Campaign::find($res->json('id'));
        $this->postJson("/api/v1/campaigns/{$campaign->id}/send")->assertOk();

        $campaign->refresh();
        $this->assertEquals(1, $campaign->sent_count);

        $messages = CampaignMessage::where('campaign_id', $campaign->id)->get();
        $this->assertCount(1, $messages);
        $this->assertStringContainsString('VIP One', $messages->first()->message);
    }

    public function test_send_lost_segment_sends_to_lost(): void
    {
        $this->createCustomers([
            ['total_spend' => 0, 'total_visits' => 5, 'last_visit_date' => now()->subDays(60)],
            ['total_spend' => 0, 'total_visits' => 1, 'last_visit_date' => now()->subDays(5)],
        ]);

        $res = $this->postJson('/api/v1/campaigns', [
            'name' => 'Lost Customers',
            'segment_type' => 'lost',
            'channel' => 'sms',
            'message_template' => 'We miss you {name}',
        ]);

        $campaign = Campaign::find($res->json('id'));
        $this->postJson("/api/v1/campaigns/{$campaign->id}/send")->assertOk();

        $campaign->refresh();
        $this->assertEquals(1, $campaign->sent_count);
    }

    public function test_send_renders_template_with_customer_data(): void
    {
        $this->createCustomers([['total_spend' => 0, 'name' => 'Ahmad']]);

        $res = $this->postJson('/api/v1/campaigns', [
            'name' => 'Template Test',
            'segment_type' => 'all',
            'channel' => 'sms',
            'message_template' => 'Hello {name}, card: {card_number}',
        ]);

        $campaign = Campaign::find($res->json('id'));
        $this->postJson("/api/v1/campaigns/{$campaign->id}/send")->assertOk();

        $msg = CampaignMessage::where('campaign_id', $campaign->id)->first();
        $this->assertStringContainsString('Ahmad', $msg->message);
        $this->assertStringContainsString('LOY-', $msg->message);
    }

    // ── Send Test ─────────────────────────────────────────────────

    public function test_send_test_sends_to_single_number(): void
    {
        $res = $this->postJson('/api/v1/campaigns', [
            'name' => 'Test Send',
            'segment_type' => 'all',
            'channel' => 'sms',
            'message_template' => 'Hello {name}',
        ]);

        $campaign = Campaign::find($res->json('id'));

        $testRes = $this->postJson("/api/v1/campaigns/{$campaign->id}/send-test", [
            'phone' => '+962781111111',
        ]);

        $testRes->assertOk();
        $this->assertTrue($testRes->json('success'));
        $this->assertEquals('+962781111111', $testRes->json('sent_to'));
    }

    public function test_send_test_rejects_invalid_phone(): void
    {
        $res = $this->postJson('/api/v1/campaigns', [
            'name' => 'Test',
            'segment_type' => 'all',
            'channel' => 'sms',
            'message_template' => 'Hi {name}',
        ]);

        $campaign = Campaign::find($res->json('id'));
        $this->postJson("/api/v1/campaigns/{$campaign->id}/send-test", [
            'phone' => 'not-a-phone',
        ])->assertStatus(422);
    }

    // ── Stats ─────────────────────────────────────────────────────

    public function test_stats_returns_breakdown(): void
    {
        $this->createCustomers([['total_spend' => 0]]);

        $res = $this->postJson('/api/v1/campaigns', [
            'name' => 'Stats Test',
            'segment_type' => 'all',
            'channel' => 'sms',
            'message_template' => 'Hi {name}',
        ]);

        $campaign = Campaign::find($res->json('id'));
        $this->postJson("/api/v1/campaigns/{$campaign->id}/send")->assertOk();

        $stats = $this->getJson("/api/v1/campaigns/{$campaign->id}/stats");
        $stats->assertOk();
        $this->assertEquals(1, $stats->json('messages.sent'));
        $this->assertArrayHasKey('status_breakdown', $stats->json());
    }

    public function test_stats_empty_when_no_messages(): void
    {
        $campaign = $this->createCampaign(['recipients_count' => 0]);

        $stats = $this->getJson("/api/v1/campaigns/{$campaign->id}/stats");
        $stats->assertOk();
        $this->assertEquals(0, $stats->json('messages.total'));
    }

    // ── Delete guard ──────────────────────────────────────────────

    public function test_delete_blocks_while_sending(): void
    {
        $campaign = $this->createCampaign(['status' => 'sending']);

        $this->deleteJson("/api/v1/campaigns/{$campaign->id}")
            ->assertStatus(422);
    }

    public function test_delete_allows_draft_and_completed(): void
    {
        $draft = $this->createCampaign(['status' => 'draft']);
        $this->deleteJson("/api/v1/campaigns/{$draft->id}")->assertOk();

        $completed = $this->createCampaign(['status' => 'completed']);
        $this->deleteJson("/api/v1/campaigns/{$completed->id}")->assertOk();
    }

    // ── Update guard ──────────────────────────────────────────────

    public function test_update_blocks_after_sending(): void
    {
        $campaign = $this->createCampaign(['status' => 'sending']);

        $this->patchJson("/api/v1/campaigns/{$campaign->id}", [
            'name' => 'Updated',
        ])->assertStatus(422);
    }

    // ── Stats endpoint ────────────────────────────────────────────

    public function test_show_includes_messages_count(): void
    {
        $campaign = $this->createCampaign();

        $res = $this->getJson("/api/v1/campaigns/{$campaign->id}");
        $res->assertOk();
        $this->assertArrayHasKey('messages_count', $res->json());
    }
}

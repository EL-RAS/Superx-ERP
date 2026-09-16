<?php

namespace App\Http\Controllers\Api\V1\Restaurant;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class TableManagementController extends Controller
{
    public function index(): JsonResponse
    {
        $tables = Cache::remember('restaurant_tables_' . request()->user()->business_id, 30, function () {
            return [
                'layout' => [
                    'floor_count' => 1,
                    'sections' => [
                        ['id' => 'main', 'label' => 'Main Hall'],
                        ['id' => 'terrace', 'label' => 'Terrace'],
                    ],
                ],
                'tables' => [
                    [
                        'id' => 'T-01',
                        'number' => '1',
                        'section' => 'main',
                        'seats' => 2,
                        'status' => 'available',
                        'shape' => 'round',
                        'position' => ['x' => 1, 'y' => 1],
                        'current_order_id' => null,
                        'occupied_since' => null,
                    ],
                    [
                        'id' => 'T-02',
                        'number' => '2',
                        'section' => 'main',
                        'seats' => 4,
                        'status' => 'occupied',
                        'shape' => 'square',
                        'position' => ['x' => 3, 'y' => 1],
                        'current_order_id' => 'ORD-1042',
                        'occupied_since' => now()->subMinutes(25)->toIso8601String(),
                    ],
                    [
                        'id' => 'T-03',
                        'number' => '3',
                        'section' => 'main',
                        'seats' => 4,
                        'status' => 'reserved',
                        'shape' => 'square',
                        'position' => ['x' => 5, 'y' => 1],
                        'current_order_id' => null,
                        'occupied_since' => null,
                        'reservation' => [
                            'guest_name' => 'Ahmed Mohamed',
                            'time' => now()->addHours(2)->toIso8601String(),
                            'party_size' => 4,
                        ],
                    ],
                    [
                        'id' => 'T-04',
                        'number' => '4',
                        'section' => 'main',
                        'seats' => 6,
                        'status' => 'available',
                        'shape' => 'rectangle',
                        'position' => ['x' => 1, 'y' => 4],
                        'current_order_id' => null,
                        'occupied_since' => null,
                    ],
                    [
                        'id' => 'T-05',
                        'number' => '5',
                        'section' => 'main',
                        'seats' => 8,
                        'status' => 'cleaning',
                        'shape' => 'rectangle',
                        'position' => ['x' => 4, 'y' => 4],
                        'current_order_id' => null,
                        'occupied_since' => null,
                    ],
                    [
                        'id' => 'T-06',
                        'number' => '6',
                        'section' => 'terrace',
                        'seats' => 2,
                        'status' => 'occupied',
                        'shape' => 'round',
                        'position' => ['x' => 1, 'y' => 1],
                        'current_order_id' => 'ORD-1043',
                        'occupied_since' => now()->subMinutes(12)->toIso8601String(),
                    ],
                    [
                        'id' => 'T-07',
                        'number' => '7',
                        'section' => 'terrace',
                        'seats' => 4,
                        'status' => 'available',
                        'shape' => 'square',
                        'position' => ['x' => 3, 'y' => 1],
                        'current_order_id' => null,
                        'occupied_since' => null,
                    ],
                    [
                        'id' => 'T-08',
                        'number' => '8',
                        'section' => 'terrace',
                        'seats' => 6,
                        'status' => 'available',
                        'shape' => 'rectangle',
                        'position' => ['x' => 1, 'y' => 4],
                        'current_order_id' => null,
                        'occupied_since' => null,
                    ],
                ],
                'summary' => [
                    'total' => 8,
                    'available' => 4,
                    'occupied' => 2,
                    'reserved' => 1,
                    'cleaning' => 1,
                ],
            ];
        });

        return response()->json($tables);
    }

    public function updateStatus(Request $request, string $tableId): JsonResponse
    {
        $validated = $request->validate([
            'status' => 'required|in:available,occupied,reserved,cleaning',
        ]);

        $tables = Cache::get('restaurant_tables_' . $request->user()->business_id, ['tables' => []]);

        foreach ($tables['tables'] as &$table) {
            if ($table['id'] === $tableId) {
                $table['status'] = $validated['status'];
                $table['occupied_since'] = $validated['status'] === 'occupied'
                    ? now()->toIso8601String()
                    : null;
                break;
            }
        }

        Cache::put('restaurant_tables_' . $request->user()->business_id, $tables, 30);

        return response()->json(['message' => 'Table status updated.', 'table_id' => $tableId, 'status' => $validated['status']]);
    }
}

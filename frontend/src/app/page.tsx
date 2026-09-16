"use client";

import { useRef, useState, Suspense, lazy } from "react";
import Link from "next/link";
import { useRouter } from "next/navigation";
import Image from "next/image";
import { motion, useInView } from "framer-motion";
import { useI18n } from "@/lib/i18n";
import { useAuthStore } from "@/stores/auth-store";
import {
  Zap, Shield, Globe, BarChart3, Layers, Sparkles,
  Database, Menu, X,
  Cpu, TrendingUp,
} from "lucide-react";
import GlowButton from "@/components/landing/GlowButton";
import TiltCard from "@/components/landing/TiltCard";

const SectorShowcase = lazy(() => import("@/components/landing/SectorShowcase"));

function FadeIn({ children, delay = 0, className = "" }: { children: React.ReactNode; delay?: number; className?: string }) {
  const ref = useRef<HTMLDivElement>(null);
  const inView = useInView(ref, { once: true, margin: "-60px" });
  return (
    <motion.div
      ref={ref}
      initial={{ opacity: 0, y: 28 }}
      animate={inView ? { opacity: 1, y: 0 } : { opacity: 0, y: 28 }}
      transition={{ duration: 0.55, delay, ease: [0.22, 1, 0.36, 1] }}
      className={className}
    >
      {children}
    </motion.div>
  );
}

function FloatingOrb({ className, delay = 0 }: { className: string; delay?: number }) {
  return (
    <motion.div
      className={className}
      animate={{ y: [0, -18, 0], opacity: [0.15, 0.35, 0.15] }}
      transition={{ duration: 6, repeat: Infinity, delay, ease: "easeInOut" }}
    />
  );
}

function MockDashboard({ t }: { t: (key: string, params?: Record<string, string>) => string }) {
  return (
    <div className="relative w-full max-w-3xl mx-auto aspect-[16/10] rounded-2xl border border-white/[0.06] bg-zinc-900/80 backdrop-blur-sm overflow-hidden shadow-2xl shadow-black/40">
      <div className="absolute inset-0 bg-gradient-to-br from-[#1E4E8C]/10 via-transparent to-[#D49A37]/5" />
      <div className="flex items-center gap-1.5 px-4 py-2.5 border-b border-white/[0.06]">
        <div className="w-2.5 h-2.5 rounded-full bg-red-500/70" />
        <div className="w-2.5 h-2.5 rounded-full bg-yellow-500/70" />
        <div className="w-2.5 h-2.5 rounded-full bg-green-500/70" />
        <span className="ms-3 text-[10px] text-zinc-500 font-mono">superx-erp.com/dashboard</span>
      </div>
      <div className="p-4 grid grid-cols-4 gap-3">
        {["JOD 24,850", "1,247", "38", "98.2%"].map((v, i) => (
          <div key={i} className="rounded-xl border border-white/[0.06] bg-white/[0.02] p-3">
            <div className="text-[10px] text-zinc-500 mb-1">{[t("landing.revenue"), t("landing.orders"), t("landing.branches"), t("landing.uptime")][i]}</div>
            <div className="text-sm font-semibold text-white">{v}</div>
          </div>
        ))}
      </div>
      <div className="px-4 pb-4 grid grid-cols-3 gap-3">
        <div className="col-span-2 rounded-xl border border-white/[0.06] bg-white/[0.02] p-3">
          <div className="text-[10px] text-zinc-500 mb-3">{t("landing.sales_trend")}</div>
          <svg viewBox="0 0 200 50" className="w-full h-12">
            <defs>
              <linearGradient id="chartGrad" x1="0" y1="0" x2="0" y2="1">
                <stop offset="0%" stopColor="#D49A37" stopOpacity="0.3" />
                <stop offset="100%" stopColor="#D49A37" stopOpacity="0" />
              </linearGradient>
            </defs>
            <path d="M0,40 Q25,35 50,28 T100,20 T150,12 T200,8" fill="none" stroke="#D49A37" strokeWidth="2" />
            <path d="M0,40 Q25,35 50,28 T100,20 T150,12 T200,8 V50 H0 Z" fill="url(#chartGrad)" />
          </svg>
        </div>
        <div className="rounded-xl border border-white/[0.06] bg-white/[0.02] p-3">
          <div className="text-[10px] text-zinc-500 mb-2">{t("landing.top_products")}</div>
          {[t("landing.product_milk"), t("landing.product_tee"), t("landing.product_iphone")].map((p, i) => (
            <div key={i} className="flex items-center justify-between py-1.5 border-b border-white/[0.04] last:border-0">
              <span className="text-[11px] text-zinc-300">{p}</span>
              <span className="text-[10px] text-emerald-400">+{(12 + i * 8).toFixed(0)}%</span>
            </div>
          ))}
        </div>
      </div>
      <div className="absolute bottom-0 left-0 right-0 h-px bg-gradient-to-r from-transparent via-[#D49A37]/30 to-transparent" />
    </div>
  );
}

export default function LandingPage() {
  const router = useRouter();
  const { locale, t } = useI18n();
  const token = useAuthStore((s) => s.token);
  const [mobileMenuOpen, setMobileMenuOpen] = useState(false);

  const goToRegister = () => {
    if (token) {
      router.push("/dashboard");
    } else {
      router.push("/register");
    }
  };

  const FEATURES = [
    { icon: Zap, titleKey: "features.morphing_engine_title", descKey: "features.morphing_engine_desc" },
    { icon: Shield, titleKey: "features.security_title", descKey: "features.security_desc" },
    { icon: Globe, titleKey: "features.arabic_title", descKey: "features.arabic_desc" },
    { icon: BarChart3, titleKey: "features.analytics_title", descKey: "features.analytics_desc" },
    { icon: Layers, titleKey: "features.verticals_title", descKey: "features.verticals_desc" },
    { icon: Sparkles, titleKey: "features.ai_title", descKey: "features.ai_desc" },
  ];

  const STATS = [
    { value: "8", labelKey: "landing.stat_verticals" },
    { value: "43", labelKey: "landing.stat_tables" },
    { value: "173+", labelKey: "landing.stat_apis" },
    { value: "50", labelKey: "landing.stat_pages" },
  ];

  const HOW_STEPS = [
    { num: "01", icon: Database, gradient: "from-[#1E4E8C] to-[#3A75C4]", titleKey: "landing.how_step1", descKey: "landing.how_step1_desc" },
    { num: "02", icon: Cpu, gradient: "from-[#D49A37] to-[#B87B28]", titleKey: "landing.how_step2", descKey: "landing.how_step2_desc" },
    { num: "03", icon: TrendingUp, gradient: "from-emerald-500 to-emerald-600", titleKey: "landing.how_step3", descKey: "landing.how_step3_desc" },
  ];

  return (
    <div className="min-h-screen bg-zinc-950 text-white overflow-x-hidden selection:bg-[#D49A37]/25 selection:text-white">
      <FloatingOrb className="fixed top-20 left-[10%] w-80 h-80 rounded-full bg-[#1E4E8C]/20 blur-[100px] pointer-events-none" delay={0} />
      <FloatingOrb className="fixed top-1/2 right-[5%] w-64 h-64 rounded-full bg-[#D49A37]/15 blur-[80px] pointer-events-none" delay={2} />
      <FloatingOrb className="fixed bottom-20 left-1/3 w-72 h-72 rounded-full bg-[#1E4E8C]/15 blur-[90px] pointer-events-none" delay={4} />

      <nav className="fixed top-0 inset-x-0 z-50 backdrop-blur-xl bg-zinc-950/70 border-b border-white/[0.06]">
        <div className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 h-20 flex items-center justify-between">
          <Link href="/" className="flex items-center gap-3">
            <Image src="/images/logobg.png" alt="superX Logo" width={100} height={100} className="w-35 h-35 object-contain rounded-xl" priority />
          </Link>
          <div className="hidden md:flex items-center gap-8">
            <button
              onClick={() => router.push(locale === "en" ? "/ar" : "/")}
              className="text-sm text-zinc-400 hover:text-white transition-colors"
            >
              {locale === "en" ? "عربي" : "EN"}
            </button>
            <GlowButton onClick={goToRegister}>{t("landing.cta_start")}</GlowButton>
          </div>
          <button onClick={() => setMobileMenuOpen(!mobileMenuOpen)} className="md:hidden p-2 text-zinc-400 hover:text-white">
            {mobileMenuOpen ? <X className="w-5 h-5" /> : <Menu className="w-5 h-5" />}
          </button>
        </div>
        {mobileMenuOpen && (
          <motion.div
            initial={{ opacity: 0, height: 0 }}
            animate={{ opacity: 1, height: "auto" }}
            exit={{ opacity: 0, height: 0 }}
            className="md:hidden border-t border-white/[0.06] bg-zinc-950/95 backdrop-blur-xl"
          >
            <div className="px-4 py-4 flex flex-col gap-3">
              <button onClick={() => { setMobileMenuOpen(false); router.push(locale === "en" ? "/ar" : "/"); }} className="text-sm text-zinc-400 hover:text-white text-start py-2">
                {locale === "en" ? "عربي" : "EN"}
              </button>
              <GlowButton onClick={() => { setMobileMenuOpen(false); goToRegister(); }}>{t("landing.cta_start")}</GlowButton>
            </div>
          </motion.div>
        )}
      </nav>

      <section className="relative pt-32 pb-20 sm:pt-40 sm:pb-28">
        <div className="absolute inset-0 bg-[radial-gradient(ellipse_80%_60%_at_50%_-20%,rgba(30,78,140,0.15),transparent)]" />
        <div className="relative max-w-5xl mx-auto px-4 sm:px-6 lg:px-8 text-center">
          <FadeIn>
            <div className="inline-flex items-center gap-2 px-4 py-1.5 rounded-full border border-[#D49A37]/20 bg-[#D49A37]/5 mb-8">
              <span className="relative flex h-2 w-2">
                <span className="animate-ping absolute inline-flex h-full w-full rounded-full bg-[#D49A37] opacity-75" />
                <span className="relative inline-flex rounded-full h-2 w-2 bg-[#D49A37]" />
              </span>
              <span className="text-xs font-medium text-[#D49A37]">{t("landing.badge")}</span>
            </div>
          </FadeIn>
          <FadeIn delay={0.1}>
            <h1 className="text-4xl sm:text-5xl lg:text-6xl font-bold tracking-tight leading-[1.1] mb-6">
              <span className="text-white">{t("landing.hero_title_1")}</span>
              <br />
              <span className="bg-gradient-to-r from-[#D49A37] via-[#F3C87A] to-[#1E4E8C] bg-clip-text text-transparent">
                {t("landing.hero_title_2")}
              </span>
            </h1>
          </FadeIn>
          <FadeIn delay={0.2}>
            <p className="text-lg sm:text-xl text-zinc-400 max-w-2xl mx-auto mb-4 leading-relaxed">
              {t("landing.hero_subtitle")}
            </p>
            <p className="text-base text-zinc-500 max-w-xl mx-auto mb-10 leading-relaxed">
              {t("landing.hero_subtitle_2")}
            </p>
          </FadeIn>
          <FadeIn delay={0.3}>
            <div className="flex flex-col sm:flex-row items-center justify-center gap-4">
              <GlowButton onClick={goToRegister}>{t("landing.cta_start")}</GlowButton>
              <GlowButton variant="secondary" onClick={goToRegister}>{t("landing.cta_demo")}</GlowButton>
            </div>
          </FadeIn>
          <FadeIn delay={0.4} className="mt-14">
            <MockDashboard t={t} />
          </FadeIn>
        </div>
      </section>

      <section className="relative border-y border-white/[0.06] bg-white/[0.01]">
        <div className="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8 py-10">
          <div className="grid grid-cols-2 md:grid-cols-4 gap-8">
            {STATS.map((stat, i) => (
              <FadeIn key={i} delay={i * 0.08} className="text-center">
                <div className="text-3xl sm:text-4xl font-bold bg-gradient-to-b from-white to-zinc-400 bg-clip-text text-transparent">
                  {stat.value}
                </div>
                <div className="text-sm text-zinc-500 mt-1">{t(stat.labelKey)}</div>
              </FadeIn>
            ))}
          </div>
        </div>
      </section>

      <section className="relative py-24 sm:py-32">
        <div className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
          <div className="text-center mb-16">
            <FadeIn>
              <h2 className="text-3xl sm:text-4xl font-bold tracking-tight mb-4">
                <span className="text-white">{t("landing.sectors_title_1")}</span>
                <span className="bg-gradient-to-r from-[#D49A37] to-[#F3C87A] bg-clip-text text-transparent">
                  {t("landing.sectors_title_2")}
                </span>
              </h2>
              <p className="text-zinc-400 text-lg max-w-xl mx-auto">{t("landing.sectors_subtitle")}</p>
            </FadeIn>
          </div>
          <FadeIn delay={0.15}>
            <Suspense fallback={<div className="text-center text-zinc-500 py-20">{t("landing.loading")}</div>}>
              <SectorShowcase />
            </Suspense>
          </FadeIn>
        </div>
      </section>

      <section className="relative py-24 sm:py-32 border-t border-white/[0.06]">
        <div className="absolute inset-0 bg-[radial-gradient(ellipse_60%_40%_at_50%_50%,rgba(30,78,140,0.08),transparent)]" />
        <div className="relative max-w-6xl mx-auto px-4 sm:px-6 lg:px-8">
          <div className="text-center mb-16">
            <FadeIn>
              <h2 className="text-3xl sm:text-4xl font-bold tracking-tight mb-4">
                <span className="text-white">{t("landing.how_title")}</span>
                <span className="bg-gradient-to-r from-[#D49A37] to-[#F3C87A] bg-clip-text text-transparent">
                  {t("landing.how_title_2")}
                </span>
              </h2>
              <p className="text-zinc-400 text-lg max-w-xl mx-auto">{t("landing.how_subtitle")}</p>
            </FadeIn>
          </div>
          <div className="grid md:grid-cols-3 gap-6 relative">
            <div className="hidden md:block absolute top-12 left-[17%] right-[17%] h-px bg-gradient-to-r from-[#1E4E8C]/50 via-[#D49A37]/50 to-emerald-500/50" />
            {HOW_STEPS.map((step, i) => (
              <FadeIn key={i} delay={i * 0.12}>
                <TiltCard>
                  <div className="relative rounded-2xl border border-white/[0.06] bg-zinc-900/60 backdrop-blur-sm p-8 hover:border-white/[0.12] transition-colors">
                    <div className={`w-12 h-12 rounded-xl bg-gradient-to-br ${step.gradient} flex items-center justify-center mb-5 shadow-lg`}>
                      <step.icon className="w-6 h-6 text-white" />
                    </div>
                    <div className="absolute top-5 right-5 text-5xl font-black text-white/[0.03]">
                      {step.num}
                    </div>
                    <h3 className="text-lg font-semibold text-white mb-2">{t(step.titleKey)}</h3>
                    <p className="text-sm text-zinc-400 leading-relaxed">{t(step.descKey)}</p>
                  </div>
                </TiltCard>
              </FadeIn>
            ))}
          </div>
        </div>
      </section>

      <section className="relative py-24 sm:py-32 border-t border-white/[0.06]">
        <div className="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8">
          <div className="text-center mb-16">
            <FadeIn>
              <h2 className="text-3xl sm:text-4xl font-bold tracking-tight mb-4">
                <span className="text-white">{t("landing.features_title_1")}</span>
                <span className="bg-gradient-to-r from-[#D49A37] to-[#F3C87A] bg-clip-text text-transparent">
                  {t("landing.features_title_2")}
                </span>
              </h2>
              <p className="text-zinc-400 text-lg max-w-xl mx-auto">{t("landing.features_subtitle")}</p>
            </FadeIn>
          </div>
          <div className="grid sm:grid-cols-2 lg:grid-cols-3 gap-5">
            {FEATURES.map((feat, i) => (
              <FadeIn key={i} delay={i * 0.08}>
                <TiltCard className="h-full">
                  <div className="h-full rounded-2xl border border-white/[0.06] bg-zinc-900/60 backdrop-blur-sm p-7 hover:border-white/[0.12] transition-colors group">
                    <div className="w-10 h-10 rounded-lg bg-[#1E4E8C]/20 border border-[#1E4E8C]/30 flex items-center justify-center mb-4 group-hover:bg-[#1E4E8C]/30 transition-colors">
                      <feat.icon className="w-5 h-5 text-[#D49A37]" />
                    </div>
                    <h3 className="text-base font-semibold text-white mb-2">{t(feat.titleKey)}</h3>
                    <p className="text-sm text-zinc-400 leading-relaxed">{t(feat.descKey)}</p>
                  </div>
                </TiltCard>
              </FadeIn>
            ))}
          </div>
        </div>
      </section>

      <section className="relative py-24 sm:py-32 border-t border-white/[0.06]">
        <div className="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8">
          <FadeIn>
            <div className="relative rounded-3xl border border-white/[0.08] bg-gradient-to-br from-[#1E4E8C]/10 via-zinc-900/80 to-[#D49A37]/5 backdrop-blur-sm p-10 sm:p-14 text-center overflow-hidden">
              <div className="absolute top-0 left-1/2 -translate-x-1/2 w-3/4 h-px bg-gradient-to-r from-transparent via-[#D49A37]/40 to-transparent" />
              <div className="absolute inset-0 bg-[radial-gradient(ellipse_at_center,rgba(212,154,55,0.05),transparent_70%)]" />
              <div className="relative">
                <h2 className="text-3xl sm:text-4xl font-bold tracking-tight mb-4">{t("landing.cta_title")}</h2>
                <p className="text-zinc-400 text-lg max-w-xl mx-auto mb-8">{t("landing.cta_desc")}</p>
                <div className="flex flex-col sm:flex-row items-center justify-center gap-4">
                  <GlowButton onClick={goToRegister}>{t("landing.cta_start")}</GlowButton>
                  <GlowButton variant="secondary" onClick={goToRegister}>{t("landing.cta_register")}</GlowButton>
                </div>
              </div>
            </div>
          </FadeIn>
        </div>
      </section>

      <footer className="border-t border-white/[0.06] bg-zinc-950">
        <div className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-12">
          <div className="flex flex-col md:flex-row items-center justify-between gap-6">
            <div className="flex items-center">
              <Image src="/images/logobg.png" alt="superX Logo" width={48} height={48} className="w-20 h-20  object-contain rounded-xl" />
              <span className="text-sm font-semibold text-white">superX ERP</span>
            </div>
            <p className="text-sm text-zinc-500 text-center">{t("landing.footer_tagline")}</p>
            <p className="text-xs text-zinc-600">&copy; 2026 superX. {t("landing.footer_rights")}</p>
          </div>
        </div>
      </footer>
    </div>
  );
}

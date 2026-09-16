"use client";

import { useState } from "react";
import { motion } from "framer-motion";
import { useAuthStore } from "@/stores/auth-store";
import { useI18n, roleLabel } from "@/lib/i18n";
import { updateProfile, changePassword } from "@/lib/api";
import { mapFieldErrors } from "@/lib/validation";
import PageHeader from "@/components/ui/PageHeader";
import PasswordInput from "@/components/ui/PasswordInput";
import AvatarUploader from "@/components/core/AvatarUploader";
import { UserRound, KeyRound, AlertCircle, CheckCircle, Loader2 } from "lucide-react";

export default function ProfilePage() {
  const { t } = useI18n();
  const { user, business, setUser, token } = useAuthStore();

  const [name, setName] = useState(user?.name ?? "");
  const [email, setEmail] = useState(user?.email ?? "");
  const [savingProfile, setSavingProfile] = useState(false);
  const [profileErrors, setProfileErrors] = useState<Record<string, string>>({});
  const [profileSaved, setProfileSaved] = useState(false);

  const [currentPassword, setCurrentPassword] = useState("");
  const [newPassword, setNewPassword] = useState("");
  const [confirmPassword, setConfirmPassword] = useState("");
  const [savingPassword, setSavingPassword] = useState(false);
  const [passwordErrors, setPasswordErrors] = useState<Record<string, string>>({});
  const [passwordMessage, setPasswordMessage] = useState<string | null>(null);

  const role = user?.role || "";
  const roleLabelText = roleLabel(t, role);

  const inputClass = (error?: string) =>
    `w-full px-4 py-2.5 bg-card/80 border rounded-xl text-sm text-foreground placeholder:text-muted focus:outline-none focus:border-primary/50 transition-colors ${error ? "border-red-500/50" : "border-border"}`;

  const fieldError = (key: string) => profileErrors[key] && (
    <p className="flex items-center gap-1.5 text-xs text-red-400 mt-1.5">
      <AlertCircle className="w-3 h-3" /> {profileErrors[key]}
    </p>
  );

  const handleSaveProfile = async () => {
    if (!business || !token || !user) return;
    setSavingProfile(true);
    setProfileErrors({});
    setProfileSaved(false);
    try {
      const updated = await updateProfile(token, business.id, {
        name,
        email,
      });
      setUser({ ...user, name: updated.user.name, email: updated.user.email });
      setProfileSaved(true);
      window.setTimeout(() => setProfileSaved(false), 2500);
    } catch (err) {
      setProfileErrors(mapFieldErrors(err, ["name", "email"]));
    } finally {
      setSavingProfile(false);
    }
  };

  const handleChangePassword = async () => {
    if (!business || !token) return;
    setSavingPassword(true);
    setPasswordErrors({});
    setPasswordMessage(null);
    try {
      await changePassword(token, business.id, {
        current_password: currentPassword,
        new_password: newPassword,
        new_password_confirmation: confirmPassword,
      });
      setPasswordMessage(t("profile.password_changed"));
      setCurrentPassword("");
      setNewPassword("");
      setConfirmPassword("");
      window.setTimeout(() => {
        window.location.href = "/login";
      }, 1800);
    } catch (err) {
      const mapped = mapFieldErrors(err, ["current_password", "new_password"]);
      setPasswordErrors(mapped);
      if (Object.keys(mapped).length === 0) {
        const message = (err as { message?: string })?.message;
        setPasswordMessage(message ? message.replace(/^Error:\s*/i, "") : t("common.error"));
      }
    } finally {
      setSavingPassword(false);
    }
  };

  return (
    <motion.div initial={{ opacity: 0 }} animate={{ opacity: 1 }} className="space-y-6">
      <PageHeader title={t("profile.title")} subtitle={t("profile.subtitle")} />

      {/* ── Personal Avatar ── */}
      <div className="glass rounded-2xl p-6">
        <div className="flex items-center gap-2 mb-5">
          <UserRound className="w-5 h-5 text-primary-light" />
          <h3 className="text-lg font-medium text-foreground">{t("profile.avatar")}</h3>
        </div>
        <div className="flex flex-col sm:flex-row items-start sm:items-center gap-5">
          <AvatarUploader size={88} />
          <div className="min-w-0">
            <p className="text-sm font-medium text-foreground">{user?.name || "—"}</p>
            <p className="text-xs text-muted mt-0.5">{user?.email || "—"}</p>
            <p className="text-xs text-muted mt-1.5">{t("profile.avatar_desc")}</p>
          </div>
        </div>
      </div>

      {/* ── Account Information ── */}
      <div className="glass rounded-2xl p-6 space-y-4">
        <div className="flex items-center gap-2">
          <UserRound className="w-5 h-5 text-primary-light" />
          <h3 className="text-lg font-medium text-foreground">{t("profile.account")}</h3>
        </div>
        <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
          <div>
            <label className="text-xs text-muted uppercase tracking-wider block mb-1.5">{t("profile.name")}</label>
            <input value={name} onChange={(e) => { setName(e.target.value); setProfileErrors((p) => ({ ...p, name: "" })); }}
              autoComplete="off" className={inputClass(profileErrors.name)} />
            {fieldError("name")}
          </div>
          <div>
            <label className="text-xs text-muted uppercase tracking-wider block mb-1.5">{t("profile.email")}</label>
            <input type="email" value={email} onChange={(e) => { setEmail(e.target.value); setProfileErrors((p) => ({ ...p, email: "" })); }}
              autoComplete="off" className={inputClass(profileErrors.email)} />
            {fieldError("email")}
          </div>
          <div>
            <label className="text-xs text-muted uppercase tracking-wider block mb-1.5">{t("profile.username")}</label>
            <p className="w-full px-4 py-2.5 bg-card/60 border border-border rounded-xl text-sm text-muted">{user?.username || "—"}</p>
          </div>
          <div>
            <label className="text-xs text-muted uppercase tracking-wider block mb-1.5">{t("profile.role")}</label>
            <p className="w-full px-4 py-2.5 bg-card/60 border border-border rounded-xl text-sm text-muted">{roleLabelText}</p>
          </div>
        </div>
        <div className="flex items-center gap-3 pt-2">
          <button onClick={handleSaveProfile} disabled={savingProfile}
            className="flex items-center gap-2 px-5 py-2.5 bg-primary hover:bg-primary-light text-foreground rounded-xl text-sm font-medium transition-colors disabled:opacity-40">
            {savingProfile ? <Loader2 className="w-4 h-4 animate-spin" /> : null}
            {savingProfile ? t("common.saving") : t("common.save")}
          </button>
          {profileSaved && (
            <span className="flex items-center gap-1.5 text-xs text-emerald-400">
              <CheckCircle className="w-4 h-4" /> {t("profile.saved")}
            </span>
          )}
        </div>
      </div>

      {/* ── Change Password ── */}
      <div className="glass rounded-2xl p-6 space-y-4">
        <div className="flex items-center gap-2">
          <KeyRound className="w-5 h-5 text-primary-light" />
          <h3 className="text-lg font-medium text-foreground">{t("profile.password")}</h3>
        </div>
        <p className="text-xs text-muted -mt-2">{t("profile.password_desc")}</p>
        <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
          <div>
            <label className="text-xs text-muted uppercase tracking-wider block mb-1.5">{t("profile.current_password")}</label>
            <PasswordInput value={currentPassword} onChange={(v) => { setCurrentPassword(v); setPasswordErrors((p) => ({ ...p, current_password: "" })); }}
              autoComplete="current-password" error={passwordErrors.current_password} />
            {passwordErrors.current_password && (
              <p className="flex items-center gap-1.5 text-xs text-red-400 mt-1.5">
                <AlertCircle className="w-3 h-3" /> {passwordErrors.current_password}
              </p>
            )}
          </div>
          <div>
            <label className="text-xs text-muted uppercase tracking-wider block mb-1.5">{t("profile.new_password")}</label>
            <PasswordInput value={newPassword} onChange={(v) => { setNewPassword(v); setPasswordErrors((p) => ({ ...p, new_password: "" })); }}
              autoComplete="new-password" error={passwordErrors.new_password} />
          </div>
          <div>
            <label className="text-xs text-muted uppercase tracking-wider block mb-1.5">{t("profile.confirm_password")}</label>
            <PasswordInput value={confirmPassword} onChange={(v) => { setConfirmPassword(v); setPasswordErrors((p) => ({ ...p, new_password: "" })); }}
              autoComplete="new-password" error={passwordErrors.new_password} />
            {passwordErrors.new_password && (
              <p className="flex items-center gap-1.5 text-xs text-red-400 mt-1.5">
                <AlertCircle className="w-3 h-3" /> {passwordErrors.new_password}
              </p>
            )}
          </div>
        </div>
        <div className="flex items-center gap-3">
          <button onClick={handleChangePassword} disabled={savingPassword || !currentPassword || !newPassword}
            className="flex items-center gap-2 px-5 py-2.5 bg-card border border-border hover:border-primary/40 text-foreground rounded-xl text-sm font-medium transition-colors disabled:opacity-40">
            {savingPassword ? <Loader2 className="w-4 h-4 animate-spin" /> : null}
            {savingPassword ? t("common.saving") : t("profile.password")}
          </button>
          {passwordMessage && (
            <span className={`flex items-center gap-1.5 text-xs ${passwordErrors.current_password || passwordErrors.new_password ? "text-red-400" : "text-emerald-400"}`}>
              <AlertCircle className="w-4 h-4" /> {passwordMessage}
            </span>
          )}
        </div>
      </div>
    </motion.div>
  );
}

"use client";

import { useRef, useState } from "react";
import { useAuthStore } from "@/stores/auth-store";
import { useI18n } from "@/lib/i18n";
import { updateProfile, ApiError } from "@/lib/api";
import { Camera, Loader2, X } from "lucide-react";

interface AvatarUploaderProps {
  size?: number;
  className?: string;
  onUploaded?: (avatar: string | null) => void;
}

export default function AvatarUploader({
  size = 88,
  className = "",
  onUploaded,
}: AvatarUploaderProps) {
  const { token, business, user, setUser } = useAuthStore();
  const { t } = useI18n();
  const inputRef = useRef<HTMLInputElement>(null);
  const [uploading, setUploading] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const avatar = user?.avatar ?? null;

  const persist = async (dataUrl: string | null) => {
    if (!token || !business || !user) return;
    setUploading(true);
    setError(null);
    try {
      const updated = await updateProfile(token, business.id, { avatar: dataUrl });
      const next = updated.user.avatar ?? null;
      setUser({ ...user, avatar: next });
      onUploaded?.(next);
    } catch (err) {
      const message =
        err instanceof ApiError
          ? err.errors?.avatar?.[0] ?? err.message
          : null;
      setError(message ? message.replace(/^Error:\s*/i, "") : t("profile.avatar_upload_failed"));
    } finally {
      setUploading(false);
    }
  };

  const handleFile = (file: File | undefined) => {
    if (!file) return;
    if (!file.type.startsWith("image/")) {
      setError(t("profile.avatar_invalid_type"));
      return;
    }
    const reader = new FileReader();
    reader.onload = () => {
      const img = new Image();
      img.onload = () => {
        const MAX = 512;
        let { width, height } = img;
        if (width > MAX || height > MAX) {
          const scale = Math.min(MAX / width, MAX / height);
          width = Math.round(width * scale);
          height = Math.round(height * scale);
        }
        const canvas = document.createElement("canvas");
        canvas.width = width;
        canvas.height = height;
        const ctx = canvas.getContext("2d");
        if (!ctx) {
          setError(t("profile.avatar_upload_failed"));
          return;
        }
        ctx.drawImage(img, 0, 0, width, height);
        persist(canvas.toDataURL("image/jpeg", 0.85));
      };
      img.onerror = () => setError(t("profile.avatar_upload_failed"));
      img.src = String(reader.result);
    };
    reader.onerror = () => setError(t("profile.avatar_upload_failed"));
    reader.readAsDataURL(file);
  };

  const remove = (e: React.MouseEvent) => {
    e.stopPropagation();
    persist(null);
  };

  const initials = user?.name
    ? user.name.trim().split(/\s+/).map((w) => w[0]).join("").toUpperCase().slice(0, 2)
    : "U";

  return (
    <div className={`relative inline-block ${className}`}>
      <input
        ref={inputRef}
        type="file"
        accept="image/*"
        className="hidden"
        onChange={(e) => handleFile(e.target.files?.[0])}
      />
      <button
        type="button"
        onClick={() => inputRef.current?.click()}
        disabled={uploading}
        className="group relative overflow-hidden flex items-center justify-center bg-card border border-border hover:border-primary/40 transition-colors rounded-full"
        style={{ width: size, height: size }}
        title={t("profile.avatar")}
      >
        {avatar ? (
          // eslint-disable-next-line @next/next/no-img-element
          <img
            src={avatar}
            alt={user?.name ?? "avatar"}
            className="w-full h-full object-cover"
          />
        ) : (
          <span
            className="w-full h-full flex items-center justify-center bg-gradient-to-br from-[#1E4E8C] to-[#D49A37]"
            style={{ fontSize: Math.max(14, size * 0.34) }}
          >
            <span className="text-white font-bold">{initials}</span>
          </span>
        )}
        {uploading ? (
          <span className="absolute inset-0 flex items-center justify-center bg-black/50 rounded-full">
            <Loader2 className="text-white animate-spin" style={{ width: size * 0.3, height: size * 0.3 }} />
          </span>
        ) : (
          <span className="absolute inset-0 flex items-center justify-center bg-black/40 opacity-0 group-hover:opacity-100 transition-opacity rounded-full">
            <Camera className="text-white" style={{ width: size * 0.28, height: size * 0.28 }} />
          </span>
        )}
      </button>
      {avatar && !uploading && (
        <button
          type="button"
          onClick={remove}
          className="absolute -top-1 -end-1 w-5 h-5 rounded-full bg-danger text-white flex items-center justify-center shadow hover:scale-110 transition-transform"
          title={t("profile.remove_avatar")}
        >
          <X className="w-3 h-3" />
        </button>
      )}
      {error && <p className="text-xs text-red-400 mt-1.5 max-w-[200px]">{error}</p>}
    </div>
  );
}

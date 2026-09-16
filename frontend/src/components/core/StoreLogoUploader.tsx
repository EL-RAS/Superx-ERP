"use client";

import { useRef, useState } from "react";
import { useAuthStore } from "@/stores/auth-store";
import { useI18n } from "@/lib/i18n";
import { updateBusinessSettings } from "@/lib/api";
import { ImagePlus, Loader2, X } from "lucide-react";

interface StoreLogoUploaderProps {
  size?: number;
  className?: string;
  interactive?: boolean;
  onUploaded?: (logo: string | null) => void;
}

export default function StoreLogoUploader({
  size = 64,
  className = "",
  interactive = true,
  onUploaded,
}: StoreLogoUploaderProps) {
  const { token, business, config, setConfig } = useAuthStore();
  const { t } = useI18n();
  const inputRef = useRef<HTMLInputElement>(null);
  const [uploading, setUploading] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const logo = config?.logo ?? null;

  const persist = async (dataUrl: string | null) => {
    if (!token || !business) return;
    setUploading(true);
    setError(null);
    try {
      const updated = await updateBusinessSettings(token, business.id, { logo: dataUrl });
      const next = updated.logo ?? null;
      if (config) setConfig({ ...config, logo: next, business_name: updated.business_name });
      onUploaded?.(next);
    } catch {
      setError(t("settings.logo_upload_failed"));
    } finally {
      setUploading(false);
    }
  };

  const handleFile = (file: File | undefined) => {
    if (!file) return;
    if (!file.type.startsWith("image/")) {
      setError(t("settings.logo_invalid_type"));
      return;
    }
    const reader = new FileReader();
    reader.onload = () => persist(String(reader.result));
    reader.onerror = () => setError(t("settings.logo_upload_failed"));
    reader.readAsDataURL(file);
  };

  const remove = (e: React.MouseEvent) => {
    e.stopPropagation();
    persist(null);
  };

  const fallbackInitial = (business?.name ?? "S").trim().charAt(0).toUpperCase();

  if (!interactive) {
    return (
      <div
        className={`relative overflow-hidden flex items-center justify-center bg-card border border-border rounded-xl ${className}`}
        style={{ width: size, height: size }}
      >
        {logo ? (
          // eslint-disable-next-line @next/next/no-img-element
          <img
            src={logo}
            alt={business?.name ?? "logo"}
            className="w-full h-full object-contain"
          />
        ) : (
          <span className="text-lg font-bold text-gold">{fallbackInitial}</span>
        )}
      </div>
    );
  }

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
        className="group relative overflow-hidden flex items-center justify-center bg-card border border-border hover:border-primary/40 transition-colors cursor-pointer"
        style={{ width: size, height: size, borderRadius: 12 }}
        title={t("settings.upload_logo")}
      >
        {logo ? (
          // eslint-disable-next-line @next/next/no-img-element
          <img
            src={logo}
            alt={business?.name ?? "logo"}
            className="w-full h-full object-contain"
            style={{ borderRadius: 12 }}
          />
        ) : (
          <span className="text-lg font-bold text-gold">{fallbackInitial}</span>
        )}
        {uploading ? (
          <span className="absolute inset-0 flex items-center justify-center bg-black/50 rounded-xl">
            <Loader2 className="w-5 h-5 text-white animate-spin" />
          </span>
        ) : (
          <span className="absolute inset-0 flex items-center justify-center bg-black/50 opacity-0 group-hover:opacity-100 transition-opacity rounded-xl">
            <ImagePlus className="w-5 h-5 text-white" />
          </span>
        )}
      </button>
      {logo && !uploading && (
        <button
          type="button"
          onClick={remove}
          className="absolute -top-1.5 -end-1.5 w-5 h-5 rounded-full bg-danger text-white flex items-center justify-center shadow hover:scale-110 transition-transform"
          title={t("settings.remove_logo")}
        >
          <X className="w-3 h-3" />
        </button>
      )}
      {error && <p className="text-xs text-red-400 mt-1.5 max-w-[200px]">{error}</p>}
    </div>
  );
}

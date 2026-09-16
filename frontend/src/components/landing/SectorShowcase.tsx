"use client";

import { useState } from "react";
import { motion, AnimatePresence } from "framer-motion";
import { useI18n } from "@/lib/i18n";

interface SectorInfo {
  slug: string;
  nameEn: string;
  nameAr: string;
  emoji: string;
  color: string;
  gradientFrom: string;
  featuresEn: string[];
  featuresAr: string[];
  sampleColumnsEn: string[];
  sampleColumnsAr: string[];
  sampleDataEn: Record<string, string>[];
  sampleDataAr: Record<string, string>[];
}

const SECTORS: SectorInfo[] = [
  {
    slug: "supermarket",
    nameEn: "Supermarket & Hypermarket",
    nameAr: "\u0633\u0648\u0628\u0631\u0645\u0627\u0631\u0643\u062A \u0648\u0647\u0627\u064A\u0628\u0631\u0645\u0627\u0631\u0643\u062A",
    emoji: "\uD83D\uDED2",
    color: "emerald",
    gradientFrom: "from-emerald-500/20 to-emerald-600/5",
    featuresEn: ["POS Terminal", "Inventory Tracking", "Expiry Management", "Barcode Scanning"],
    featuresAr: ["\u0646\u0642\u0637\u0629 \u0627\u0644\u0628\u064A\u0639", "\u062A\u062A\u0628\u0639 \u0627\u0644\u0645\u062E\u0632\u0648\u0646", "\u0625\u062F\u0627\u0631\u0629 \u0627\u0644\u0627\u0646\u062A\u0647\u0627\u0621", "\u0645\u0633\u062D \u0628\u0637\u0627\u0642\u064A"],
    sampleColumnsEn: ["Product", "Category", "Stock", "Price", "Expiry"],
    sampleColumnsAr: ["\u0627\u0644\u0645\u0646\u062A\u062C", "\u0627\u0644\u0641\u0626\u0629", "\u0627\u0644\u0645\u062E\u0632\u0648\u0646", "\u0627\u0644\u0633\u0639\u0631", "\u0627\u0644\u0627\u0646\u062A\u0647\u0627\u0621"],
    sampleDataEn: [
      { Product: "Organic Milk 1L", Category: "Dairy", Stock: "142", Price: "JOD 2.50", Expiry: "Jul 28" },
      { Product: "Whole Wheat Bread", Category: "Bakery", Stock: "86", Price: "JOD 1.20", Expiry: "Jul 25" },
    ],
    sampleDataAr: [
      { "\u0627\u0644\u0645\u0646\u062A\u062C": "\u062D\u0644\u064A\u0628 \u0639\u0638\u0641\u064A 1\u0644", "\u0627\u0644\u0641\u0626\u0629": "\u0623\u0644\u0628\u0627\u0646", "\u0627\u0644\u0645\u062E\u0632\u0648\u0646": "142", "\u0627\u0644\u0633\u0639\u0631": "2.50 \u062F.\u0623", "\u0627\u0644\u0627\u0646\u062A\u0647\u0627\u0621": "28 \u062A\u0634\u0631\u064A\u0646" },
      { "\u0627\u0644\u0645\u0646\u062A\u062C": "\u062E\u0628\u0632 \u0642\u0645\u062D \u0623\u0633\u0645\u0646\u062A", "\u0627\u0644\u0641\u0626\u0629": "\u0645\u062E\u0628\u0632", "\u0627\u0644\u0645\u062E\u0632\u0648\u0646": "86", "\u0627\u0644\u0633\u0639\u0631": "1.20 \u062F.\u0623", "\u0627\u0644\u0627\u0646\u062A\u0647\u0627\u0621": "25 \u062A\u0634\u0631\u064A\u0646" },
    ],
  },
  {
    slug: "clothing_apparel",
    nameEn: "Fashion & Apparel",
    nameAr: "\u0623\u0632\u064A\u0627\u0621 \u0648\u0645\u0644\u0627\u0628\u0633",
    emoji: "\uD83D\uDC55",
    color: "pink",
    gradientFrom: "from-pink-500/20 to-rose-600/5",
    featuresEn: ["Variant Management", "Size/Color Matrix", "Collection Tracking", "Season Planning"],
    featuresAr: ["\u0625\u062F\u0627\u0631\u0629 \u0627\u0644\u0623\u0644\u0648\u0627\u0646", "\u0645\u062D\u0631\u0631 \u0627\u0644\u0642\u064A\u0627\u0633/\u0627\u0644\u0644\u0648\u0646", "\u062A\u062A\u0628\u0639 \u0627\u0644\u0645\u062C\u0645\u0648\u0639\u0627\u062A", "\u062A\u062E\u0637\u064A\u0637 \u0627\u0644\u0645\u0648\u0633\u0645"],
    sampleColumnsEn: ["Item", "Size", "Color", "Stock", "Price"],
    sampleColumnsAr: ["\u0627\u0644\u0639\u0646\u0635\u0631", "\u0627\u0644\u0642\u064A\u0627\u0633", "\u0627\u0644\u0644\u0648\u0646", "\u0627\u0644\u0645\u062E\u0632\u0648\u0646", "\u0627\u0644\u0633\u0639\u0631"],
    sampleDataEn: [
      { Item: "Linen Blazer", Size: "M", Color: "Navy", Stock: "24", Price: "JOD 89.00" },
      { Item: "Cotton T-Shirt", Size: "L", Color: "White", Stock: "67", Price: "JOD 15.00" },
    ],
    sampleDataAr: [
      { "\u0627\u0644\u0639\u0646\u0635\u0631": "\u0633\u062A\u0631\u0629 \u0628\u0631\u062F\u064A", "\u0627\u0644\u0642\u064A\u0627\u0633": "M", "\u0627\u0644\u0644\u0648\u0646": "\u0623\u0632\u0631\u0642", "\u0627\u0644\u0645\u062E\u0632\u0648\u0646": "24", "\u0627\u0644\u0633\u0639\u0631": "89.00 \u062F.\u0623" },
      { "\u0627\u0644\u0639\u0646\u0635\u0631": "\u0642\u0645\u064A\u0635\u0631 T", "\u0627\u0644\u0642\u064A\u0627\u0633": "L", "\u0627\u0644\u0644\u0648\u0646": "\u0623\u0628\u064A\u0636", "\u0627\u0644\u0645\u062E\u0632\u0648\u0646": "67", "\u0627\u0644\u0633\u0639\u0631": "15.00 \u062F.\u0623" },
    ],
  },
  {
    slug: "electronics_warranty",
    nameEn: "Electronics & Warranty",
    nameAr: "\u0625\u0644\u0643\u062A\u0631\u0648\u0646\u064A\u0627\u062A \u0648\u0636\u0645\u0627\u0646\u0627\u062A",
    emoji: "\uD83D\uDCF1",
    color: "blue",
    gradientFrom: "from-[#1E4E8C]/20 to-blue-600/5",
    featuresEn: ["Serial Number Tracking", "Warranty Management", "IMEI Logging", "Service Tickets"],
    featuresAr: ["\u062A\u062A\u0628\u0639 \u0627\u0644\u0623\u0631\u0642\u0627\u0645", "\u0625\u062F\u0627\u0631\u0629 \u0627\u0644\u0636\u0645\u0627\u0646", "\u062A\u0633\u062C\u064A\u0644 IMEI", "\u062A\u0624\u0642\u0631 \u0627\u0644\u062E\u062F\u0645\u0629"],
    sampleColumnsEn: ["Device", "Serial", "Warranty", "Status", "Customer"],
    sampleColumnsAr: ["\u0627\u0644\u062C\u0647\u0627\u0632", "\u0627\u0644\u0631\u0642\u0645", "\u0627\u0644\u0636\u0645\u0627\u0646\u0629", "\u0627\u0644\u062D\u0627\u0644\u0629", "\u0627\u0644\u0639\u0645\u064A\u0644"],
    sampleDataEn: [
      { Device: "iPhone 16 Pro", Serial: "SN-8821", Warranty: "Active", Status: "In Stock", Customer: "-" },
      { Device: "Samsung S25", Serial: "SN-9934", Warranty: "Active", Status: "Sold", Customer: "Ahmad K." },
    ],
    sampleDataAr: [
      { "\u0627\u0644\u062C\u0647\u0627\u0632": "iPhone 16 Pro", "\u0627\u0644\u0631\u0642\u0645": "SN-8821", "\u0627\u0644\u0636\u0645\u0627\u0646\u0629": "\u0646\u0634\u0637\u0629", "\u0627\u0644\u062D\u0627\u0644\u0629": "\u0641\u064A \u0627\u0644\u0645\u062E\u0632\u0648\u0646", "\u0627\u0644\u0639\u0645\u064A\u0644": "-" },
      { "\u0627\u0644\u062C\u0647\u0627\u0632": "Samsung S25", "\u0627\u0644\u0631\u0642\u0645": "SN-9934", "\u0627\u0644\u0636\u0645\u0627\u0646\u0629": "\u0646\u0634\u0637\u0629", "\u0627\u0644\u062D\u0627\u0644\u0629": "\u0645\u0628\u064A\u0639", "\u0627\u0644\u0639\u0645\u064A\u0644": "\u0623\u062D\u0645\u062F \u0643." },
    ],
  },
  {
    slug: "fast_food_kds",
    nameEn: "Fast Food / KDS",
    nameAr: "\u0648\u062C\u0628\u0627\u062A \u0633\u0631\u064A\u0639\u0629 / \u0646\u0638\u0627\u0645 \u0645\u0637\u0628\u062E",
    emoji: "\uD83C\uDF54",
    color: "orange",
    gradientFrom: "from-orange-500/20 to-amber-600/5",
    featuresEn: ["Kitchen Display System", "Order Routing", "Timer Alerts", "Menu Management"],
    featuresAr: ["\u0634\u0627\u0634\u0629 \u0627\u0644\u0645\u0637\u0628\u062E", "\u062A\u0648\u062C\u064A\u0647 \u0627\u0644\u0637\u0644\u0628\u0627\u062A", "\u062A\u0646\u0628\u064A\u0647\u0627\u062A \u0627\u0644\u0645\u0648\u0639\u062F", "\u0625\u062F\u0627\u0631\u0629 \u0627\u0644\u0642\u0627\u0626\u0645\u0629"],
    sampleColumnsEn: ["Order", "Table", "Items", "Status", "Time"],
    sampleColumnsAr: ["\u0627\u0644\u0637\u0644\u0628", "\u0627\u0644\u0637\u0627\u0648\u0644\u0629", "\u0627\u0644\u0623\u0635\u0646\u0627\u0641", "\u0627\u0644\u062D\u0627\u0644\u0629", "\u0627\u0644\u0648\u0642\u062A"],
    sampleDataEn: [
      { Order: "KO-A3F2", Table: "T5", Items: "2x Burger", Status: "Preparing", Time: "4m" },
      { Order: "KO-B7C1", Table: "Takeaway", Items: "1x Pizza", Status: "Ready", Time: "12m" },
    ],
    sampleDataAr: [
      { "\u0627\u0644\u0637\u0644\u0628": "KO-A3F2", "\u0627\u0644\u0637\u0627\u0648\u0644\u0629": "T5", "\u0627\u0644\u0623\u0635\u0646\u0627\u0641": "2\u0640 \u0628\u0631\u063A\u0631", "\u0627\u0644\u062D\u0627\u0644\u0629": "\u062A\u062D\u0636\u064A\u0631", "\u0627\u0644\u0648\u0642\u062A": "4 \u062F" },
      { "\u0627\u0644\u0637\u0644\u0628": "KO-B7C1", "\u0627\u0644\u0637\u0627\u0648\u0644\u0629": "\u062A\u0635\u0631\u064A\u0641", "\u0627\u0644\u0623\u0635\u0646\u0627\u0641": "1\u0640 \u0628\u064A\u0632\u0627", "\u0627\u0644\u062D\u0627\u0644\u0629": "\u062C\u0627\u0647\u0632", "\u0627\u0644\u0648\u0642\u062A": "12 \u062F" },
    ],
  },
  {
    slug: "fine_dining_reservations",
    nameEn: "Fine Dining",
    nameAr: "\u0645\u0637\u0627\u0639\u0645 \u0631\u0627\u0642\u064A\u0629",
    emoji: "\uD83C\uDF7D\uFE0F",
    color: "violet",
    gradientFrom: "from-violet-500/20 to-purple-600/5",
    featuresEn: ["Reservation System", "Table Management", "Guest Profiles", "Floor Planning"],
    featuresAr: ["\u0646\u0638\u0627\u0645 \u0627\u0644\u062D\u062C\u0648\u0632\u0627\u062A", "\u0625\u062F\u0627\u0631\u0629 \u0627\u0644\u0637\u0648\u0644\u0627\u062A", "\u0645\u0644\u0641\u0627\u062A \u0627\u0644\u0636\u064A\u0641", "\u062A\u062E\u0637\u064A\u0637 \u0627\u0644\u0645\u0637\u0639\u0645"],
    sampleColumnsEn: ["Guest", "Date", "Time", "Party", "Table"],
    sampleColumnsAr: ["\u0627\u0644\u0636\u064A\u0641", "\u0627\u0644\u062A\u0627\u0631\u064A\u062E", "\u0627\u0644\u0648\u0642\u062A", "\u0627\u0644\u0645\u062C\u0645\u0648\u0639\u0629", "\u0627\u0644\u0637\u0627\u0648\u0644\u0629"],
    sampleDataEn: [
      { Guest: "Sarah M.", Date: "Jul 23", Time: "8:00 PM", Party: "4", Table: "Window-2" },
      { Guest: "Khalid R.", Date: "Jul 23", Time: "9:30 PM", Party: "2", Table: "VIP-1" },
    ],
    sampleDataAr: [
      { "\u0627\u0644\u0636\u064A\u0641": "\u0633\u0627\u0631\u0629 \u0645.", "\u0627\u0644\u062A\u0627\u0631\u064A\u062E": "23 \u062A\u0634\u0631\u064A\u0646", "\u0627\u0644\u0648\u0642\u062A": "8:00 \u0645", "\u0627\u0644\u0645\u062C\u0645\u0648\u0639\u0629": "4", "\u0627\u0644\u0637\u0627\u0648\u0644\u0629": "\u0646\u0627\u0641\u0630\u0629-2" },
      { "\u0627\u0644\u0636\u064A\u0641": "\u062E\u0627\u0644\u062F \u0631.", "\u0627\u0644\u062A\u0627\u0631\u064A\u062E": "23 \u062A\u0634\u0631\u064A\u0646", "\u0627\u0644\u0648\u0642\u062A": "9:30 \u0645", "\u0627\u0644\u0645\u062C\u0645\u0648\u0639\u0629": "2", "\u0627\u0644\u0637\u0627\u0648\u0644\u0629": "VIP-1" },
    ],
  },
  {
    slug: "dental_charting",
    nameEn: "Dental Clinic",
    nameAr: "\u0639\u064A\u0627\u062F\u0629 \u0623\u0633\u0646\u0627\u0646",
    emoji: "\uD83E\uDDB7",
    color: "cyan",
    gradientFrom: "from-cyan-500/20 to-teal-600/5",
    featuresEn: ["Dental Chart", "Treatment Plans", "X-Ray Integration", "Insurance Claims"],
    featuresAr: ["\u062E\u0631\u064A\u0637\u0629 \u0627\u0644\u0623\u0633\u0646\u0627\u0646", "\u062E\u0637\u0637 \u0627\u0644\u0639\u0644\u0627\u062C", "\u062A\u062F\u0639\u0645 \u0627\u0644\u0623\u0634\u0639\u0629", "\u0645\u0637\u0627\u0644\u0628\u0627\u062A \u0627\u0644\u062A\u0623\u0645\u064A\u0646"],
    sampleColumnsEn: ["Tooth", "Condition", "Treatment", "Status", "Cost"],
    sampleColumnsAr: ["\u0627\u0644\u0633\u0646", "\u0627\u0644\u062D\u0627\u0644\u0629", "\u0627\u0644\u0639\u0644\u0627\u062C", "\u0627\u0644\u0625\u062C\u0631\u0627\u0621", "\u0627\u0644\u062A\u0643\u0644\u0641\u0629"],
    sampleDataEn: [
      { Tooth: "#14", Condition: "Cavity", Treatment: "Filling", Status: "Planned", Cost: "JOD 45.00" },
      { Tooth: "#7", Condition: "Clean", Treatment: "Cleaning", Status: "Done", Cost: "JOD 30.00" },
    ],
    sampleDataAr: [
      { "\u0627\u0644\u0633\u0646": "#14", "\u0627\u0644\u062D\u0627\u0644\u0629": "\u062B\u062E\u0631\u0629", "\u0627\u0644\u0639\u0644\u0627\u062C": "\u062D\u0634\u0648\u0631\u0629", "\u0627\u0644\u0625\u062C\u0631\u0627\u0621": "\u0645\u062E\u0637\u0637", "\u0627\u0644\u062A\u0643\u0644\u0641\u0629": "45.00 \u062F.\u0623" },
      { "\u0627\u0644\u0633\u0646": "#7", "\u0627\u0644\u062D\u0627\u0644\u0629": "\u0635\u062D\u064A\u062D\u0629", "\u0627\u0644\u0639\u0644\u0627\u062C": "\u062A\u0646\u0638\u064A\u0641", "\u0627\u0644\u0625\u062C\u0631\u0627\u0621": "\u0645\u0646\u062C\u0632", "\u0627\u0644\u062A\u0643\u0644\u0641\u0629": "30.00 \u062F.\u0623" },
    ],
  },
  {
    slug: "general_clinic",
    nameEn: "General Clinic",
    nameAr: "\u0639\u064A\u0627\u062F\u0629 \u0639\u0627\u0645\u0629",
    emoji: "\uD83E\uDE7A",
    color: "red",
    gradientFrom: "from-red-500/20 to-rose-600/5",
    featuresEn: ["EMR (SOAP Notes)", "Prescriptions", "Lab Orders", "Appointment Scheduling"],
    featuresAr: ["\u0633\u062C\u0644\u0627\u062A \u0637\u0628\u064A\u0629 (SOAP)", "\u0627\u0644\u0648\u0635\u0641\u0627\u062A", "\u0637\u0644\u0628\u0627\u062A \u0627\u0644\u0645\u062E\u062A\u0628\u0631", "\u062C\u062F\u0648\u0644 \u0627\u0644\u0645\u0648\u0639\u064A\u062F\u0627\u062A"],
    sampleColumnsEn: ["Patient", "Visit Type", "Doctor", "Diagnosis", "Status"],
    sampleColumnsAr: ["\u0627\u0644\u0645\u0631\u0636\u0649", "\u0646\u0648\u0639 \u0627\u0644\u0632\u064A\u0627\u0631\u0629", "\u0627\u0644\u0637\u0628\u064A\u0628", "\u0627\u0644\u062A\u0634\u062E\u064A\u0635", "\u0627\u0644\u062D\u0627\u0644\u0629"],
    sampleDataEn: [
      { Patient: "Fatima H.", VisitType: "Follow-up", Doctor: "Dr. Ali", Diagnosis: "Hypertension", Status: "Completed" },
      { Patient: "Omar S.", VisitType: "New", Doctor: "Dr. Noor", Diagnosis: "-", Status: "Scheduled" },
    ],
    sampleDataAr: [
      { "\u0627\u0644\u0645\u0631\u0636\u0649": "\u0641\u0627\u0637\u0645\u0629 \u062D.", "\u0646\u0648\u0639 \u0627\u0644\u0632\u064A\u0627\u0631\u0629": "\u0645\u062A\u0627\u0628\u0639\u0629", "\u0627\u0644\u0637\u0628\u064A\u0628": "\u062F. \u0639\u0644\u064A", "\u0627\u0644\u062A\u0634\u062E\u064A\u0635": "\u0636\u063A\u0637 \u0627\u0644\u062F\u0645", "\u0627\u0644\u062D\u0627\u0644\u0629": "\u0645\u0646\u062C\u0632" },
      { "\u0627\u0644\u0645\u0631\u0636\u0649": "\u0639\u0645\u0631 \u0633.", "\u0646\u0648\u0639 \u0627\u0644\u0632\u064A\u0627\u0631\u0629": "\u062C\u062F\u064A\u062F", "\u0627\u0644\u0637\u0628\u064A\u0628": "\u062F. \u0646\u0648\u0631", "\u0627\u0644\u062A\u0634\u062E\u064A\u0635": "-", "\u0627\u0644\u062D\u0627\u0644\u0629": "\u0645\u062D\u0638\u0638" },
    ],
  },
  {
    slug: "jewelry_store",
    nameEn: "Jewelry Store",
    nameAr: "\u0645\u062D\u0644 \u0645\u062C\u0648\u0647\u0631\u0627\u062A",
    emoji: "\uD83D\uDC8E",
    color: "amber",
    gradientFrom: "from-[#D49A37]/20 to-yellow-600/5",
    featuresEn: ["Gold Rate Tracking", "Police Book", "Repair Tickets", "Weight/Karat Management"],
    featuresAr: ["\u062A\u062A\u0628\u0639 \u0633\u0639\u0631 \u0627\u0644\u0630\u0647\u0628", "\u0627\u0644\u062F\u0641\u062A\u0631 \u0627\u0644\u0634\u0631\u0637\u064A", "\u062A\u0624\u0642\u0631 \u0627\u0644\u0625\u0635\u0644\u0627\u062D\u0627\u062A", "\u0625\u062F\u0627\u0631\u0629 \u0627\u0644\u0648\u0632\u0646/\u0627\u0644\u0639\u064A\u0646"],
    sampleColumnsEn: ["Item", "Weight", "Karat", "Customer", "Status"],
    sampleColumnsAr: ["\u0627\u0644\u0639\u0646\u0635\u0631", "\u0627\u0644\u0648\u0632\u0646", "\u0627\u0644\u0639\u064A\u0646", "\u0627\u0644\u0639\u0645\u064A\u0644", "\u0627\u0644\u062D\u0627\u0644\u0629"],
    sampleDataEn: [
      { Item: "Gold Ring", Weight: "8.5g", Karat: "21K", Customer: "Layla A.", Status: "Repair" },
      { Item: "Necklace", Weight: "22.3g", Karat: "21K", Customer: "Hala M.", Status: "Sold" },
    ],
    sampleDataAr: [
      { "\u0627\u0644\u0639\u0646\u0635\u0631": "\u062E\u0627\u0648\u0635\u0631\u0629 \u0630\u0647\u0628", "\u0627\u0644\u0648\u0632\u0646": "8.5\u063A", "\u0627\u0644\u0639\u064A\u0646": "21\u0639", "\u0627\u0644\u0639\u0645\u064A\u0644": "\u0644\u064A\u0644\u0649 \u0623.", "\u0627\u0644\u062D\u0627\u0644\u0629": "\u0625\u0635\u0644\u0627\u062D" },
      { "\u0627\u0644\u0639\u0646\u0635\u0631": "\u0639\u0642\u062F", "\u0627\u0644\u0648\u0632\u0646": "22.3\u063A", "\u0627\u0644\u0639\u064A\u0646": "21\u0639", "\u0627\u0644\u0639\u0645\u064A\u0644": "\u0647\u0644\u0627 \u0645.", "\u0627\u0644\u062D\u0627\u0644\u0629": "\u0645\u0628\u064A\u0639" },
    ],
  },
];

function SectorCard({
  sector,
  isActive,
  onClick,
}: {
  sector: SectorInfo;
  isActive: boolean;
  onClick: () => void;
}) {
  const { locale } = useI18n();
  const name = locale === "ar" ? sector.nameAr : sector.nameEn;
  const features = locale === "ar" ? sector.featuresAr : sector.featuresEn;
  return (
    <motion.button
      onClick={onClick}
      whileHover={{ scale: 1.03, y: -2 }}
      whileTap={{ scale: 0.98 }}
      className={`relative p-4 rounded-2xl text-start transition-all duration-300 overflow-hidden ${
        isActive
          ? "bg-card border-2 border-gold/50 shadow-lg shadow-gold/10"
          : "bg-card border border-border hover:border-border-hover"
      }`}
    >
      {isActive && (
        <div className="absolute inset-0 bg-gradient-to-br from-gold/10 via-transparent to-navy/5 pointer-events-none" />
      )}
      <div className="relative z-10">
        <div className="flex items-center gap-3 mb-2">
          <span className="text-2xl">{sector.emoji}</span>
          <div>
            <p className="text-sm font-semibold text-foreground">{name}</p>
          </div>
        </div>
        <div className="flex flex-wrap gap-1.5 mt-3">
          {features.slice(0, 2).map((f) => (
            <span key={f} className="text-[10px] px-2 py-0.5 rounded-full bg-accent-dim text-accent border border-gold/20">
              {f}
            </span>
          ))}
        </div>
      </div>
    </motion.button>
  );
}

function SectorPreview({ sector }: { sector: SectorInfo }) {
  const { locale } = useI18n();
  const name = locale === "ar" ? sector.nameAr : sector.nameEn;
  const features = locale === "ar" ? sector.featuresAr : sector.featuresEn;
  const sampleColumns = locale === "ar" ? sector.sampleColumnsAr : sector.sampleColumnsEn;
  const sampleData = locale === "ar" ? sector.sampleDataAr : sector.sampleDataEn;
  return (
    <motion.div
      key={sector.slug}
      initial={{ opacity: 0, y: 20 }}
      animate={{ opacity: 1, y: 0 }}
      exit={{ opacity: 0, y: -20 }}
      transition={{ duration: 0.4 }}
      className="backdrop-blur-xl bg-card border border-border rounded-2xl overflow-hidden"
    >
      <div className={`px-6 py-4 bg-gradient-to-r ${sector.gradientFrom}`}>
        <div className="flex items-center gap-3">
          <span className="text-3xl">{sector.emoji}</span>
          <div>
            <h4 className="text-base font-semibold text-foreground">{name}</h4>
          </div>
        </div>
      </div>

      <div className="px-6 py-4">
        <div className="grid grid-cols-2 gap-2 mb-4">
          {features.map((f) => (
            <div key={f} className="flex items-center gap-2 text-xs text-foreground">
              <div className="w-1.5 h-1.5 rounded-full bg-gold flex-shrink-0" />
              {f}
            </div>
          ))}
        </div>

        <div className="rounded-xl overflow-hidden border border-border">
          <div className="overflow-x-auto">
            <table className="w-full text-xs">
              <thead>
                <tr className="bg-card-hover">
                  {sampleColumns.map((col) => (
                    <th key={col} className="px-3 py-2 text-start text-muted font-medium whitespace-nowrap">
                      {col}
                    </th>
                  ))}
                </tr>
              </thead>
              <tbody>
                {sampleData.map((row, i) => (
                  <tr key={i} className="border-t border-border">
                    {sampleColumns.map((col) => (
                      <td key={col} className="px-3 py-2 text-foreground whitespace-nowrap">
                        {row[col] || "—"}
                      </td>
                    ))}
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </div>
      </div>
    </motion.div>
  );
}

export default function SectorShowcase() {
  const [active, setActive] = useState(0);

  return (
    <div className="grid grid-cols-1 lg:grid-cols-12 gap-6">
      <div className="lg:col-span-4 grid grid-cols-2 gap-3">
        {SECTORS.map((sector, i) => (
          <SectorCard
            key={sector.slug}
            sector={sector}
            isActive={i === active}
            onClick={() => setActive(i)}
          />
        ))}
      </div>
      <div className="lg:col-span-8">
        <AnimatePresence mode="wait">
          <SectorPreview sector={SECTORS[active]} />
        </AnimatePresence>
      </div>
    </div>
  );
}

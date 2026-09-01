"use client";

import { create } from "zustand";

interface AppState {
  selectedPageId: string | null;
  selectPage: (id: string | null) => void;
  pageFilters: { niche: string; cpt: string; coverage: string; q: string };
  setPageFilter: (key: "niche" | "cpt" | "coverage" | "q", value: string) => void;
  selectedStockImageId: string | null;
  selectStockImage: (id: string | null) => void;
}

export const useAppStore = create<AppState>((set) => ({
  selectedPageId: null,
  selectPage: (id) => set({ selectedPageId: id }),
  pageFilters: { niche: "", cpt: "", coverage: "", q: "" },
  setPageFilter: (key, value) =>
    set((s) => ({ pageFilters: { ...s.pageFilters, [key]: value } })),
  selectedStockImageId: null,
  selectStockImage: (id) => set({ selectedStockImageId: id }),
}));

import { defineStore } from 'pinia';

const SIDEBAR_KEY = 'atlas_sidebar_collapsed';

export const useUiStore = defineStore('ui', {
    state: () => ({
        sidebarCollapsed: localStorage.getItem(SIDEBAR_KEY) === '1',
        mobileOpen: false,
        toasts: [],
    }),
    actions: {
        toggleSidebar() {
            if (window.matchMedia('(max-width: 768px)').matches) {
                this.mobileOpen = !this.mobileOpen;
            } else {
                this.sidebarCollapsed = !this.sidebarCollapsed;
                localStorage.setItem(SIDEBAR_KEY, this.sidebarCollapsed ? '1' : '0');
            }
        },
        closeMobile() {
            this.mobileOpen = false;
        },
        pushToast(t) {
            const id = Math.random().toString(36).slice(2);
            this.toasts.push({ id, ...t });
            setTimeout(() => {
                this.toasts = this.toasts.filter(x => x.id !== id);
            }, t.timeout ?? 3500);
        },
    },
});

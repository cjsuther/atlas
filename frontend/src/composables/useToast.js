import { useUiStore } from '@/stores/ui';

export function useToast() {
    const ui = useUiStore();
    return {
        success: (msg, timeout) => ui.pushToast({ type: 'success', msg, timeout }),
        error:   (msg, timeout) => ui.pushToast({ type: 'error',   msg, timeout: timeout ?? 5000 }),
        info:    (msg, timeout) => ui.pushToast({ type: 'info',    msg, timeout }),
    };
}

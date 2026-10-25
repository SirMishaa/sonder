export type TokenStore = {
    get(): Promise<string | null>;
    set(token: string): Promise<void>;
    clear(): Promise<void>;
};

const item = storage.defineItem<string | null>('local:sonderToken', {
    fallback: null,
});

/** The Sanctum token Sonder handed out, kept in extension storage. */
export function tokenStore(): TokenStore {
    return {
        get: () => item.getValue(),
        set: (token) => item.setValue(token),
        clear: () => item.removeValue(),
    };
}

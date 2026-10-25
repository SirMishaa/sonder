import { createApi } from '@/lib/api';
import { createMessageListener } from '@/lib/background';
import { SONDER_URL } from '@/lib/config';
import { tokenStore } from '@/lib/token';

export default defineBackground(() => {
    const token = tokenStore();

    browser.runtime.onMessage.addListener(
        createMessageListener({
            token,
            api: createApi({ baseUrl: SONDER_URL, token }),
            sonderIsOpen: async () =>
                (await browser.tabs.query({ url: `${SONDER_URL}/*` })).length >
                0,
            openSonder: async () => {
                await browser.tabs.create({ url: `${SONDER_URL}/dashboard` });
            },
        }),
    );
});

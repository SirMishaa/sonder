# Sonder extension

Sends YouTube videos and mixes to Sonder. Built with WXT; part of the root bun workspace.

```sh
bun install                    # from the repository root
bun run build:extension        # production → extension/.output/chrome-mv3
bun run build:extension:dev    # local Sonder → extension/.output/chrome-mv3-dev
cd extension && bun run dev    # Chrome with hot reload, local Sonder
cd extension && bun run test
```

Load the output folder unpacked in Brave or Chrome, then connect it from
Sonder's Settings → Browser extension.

Development builds talk to `http://localhost:8003` (`php artisan dev`),
production builds to `https://sonder.mishaa.dev`. `WXT_SONDER_URL`
overrides both.

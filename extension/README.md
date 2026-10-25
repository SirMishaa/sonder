# Sonder extension

Sends YouTube videos and mixes to Sonder. Built with WXT; part of the root bun workspace.

```sh
bun install          # from the repository root
cd extension
bun run dev          # Chrome/Brave with hot reload
bun run build        # .output/chrome-mv3, load it unpacked
bun run test
```

`WXT_SONDER_URL` overrides the Sonder origin (default https://sonder.mishaa.dev).

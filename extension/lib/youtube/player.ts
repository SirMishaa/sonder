/** Pauses the YouTube video playing in this tab, if any. */
export function pauseYouTube(doc: Document = document): void {
    doc.querySelector<HTMLVideoElement>('#movie_player video')?.pause();
}

if (!window.geoflowSiteVideoBound) {
    window.geoflowSiteVideoBound = true;
    document.addEventListener('click', function (event) {
        const button = event.target.closest('[data-site-video-play]');
        if (!button) return;
        const video = button.parentElement.querySelector('video[data-site-video-src]');
        if (!video) return;
        video.src = video.dataset.siteVideoSrc;
        button.hidden = true;
        video.hidden = false;
        video.play().catch(() => {
            // Controls remain available if the browser blocks scripted playback.
        });
    });
}

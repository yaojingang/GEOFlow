(() => {
    const readMeta = (name) => document.querySelector(`meta[name="${name}"]`)?.content?.trim() || "";
    const endpoint = readMeta("geoflow-telemetry-endpoint");
    const event = readMeta("geoflow-telemetry-event");
    const instanceId = readMeta("geoflow-telemetry-instance");
    const userHash = readMeta("geoflow-telemetry-user");
    const version = readMeta("geoflow-telemetry-version");
    const intervalSeconds = Math.max(3600, Number(readMeta("geoflow-telemetry-interval")) || 86400);

    if (!endpoint || !event || !instanceId || !userHash || !version) {
        return;
    }

    const storageKey = `geoflow:pulse:${instanceId}:${userHash}`;
    const now = Date.now();

    try {
        const lastSentAt = Number(window.localStorage.getItem(storageKey) || 0);
        if (lastSentAt > 0 && now - lastSentAt < intervalSeconds * 1000) {
            return;
        }
    } catch {
        // Storage can be unavailable in privacy-restricted browser contexts.
    }

    const body = new URLSearchParams({
        event,
        instance_id: instanceId,
        user_hash: userHash,
        version,
    });

    fetch(endpoint, {
        method: "POST",
        body,
        mode: "cors",
        credentials: "omit",
        keepalive: true,
        referrerPolicy: "no-referrer",
    })
        .then((response) => {
            if (!response.ok) {
                return;
            }

            try {
                window.localStorage.setItem(storageKey, String(now));
            } catch {
                // The collector still deduplicates repeated daily events.
            }
        })
        .catch(() => {
            // Telemetry failures never affect the admin page.
        });
})();

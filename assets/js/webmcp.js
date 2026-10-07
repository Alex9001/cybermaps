(function () {
    "use strict";

    var config = window.cybermapsWebMCP || {};
    var modelContext = null;

    if (document.modelContext && typeof document.modelContext.registerTool === "function") {
        modelContext = document.modelContext;
    } else if (navigator.modelContext && typeof navigator.modelContext.registerTool === "function") {
        modelContext = navigator.modelContext;
    }

    if (!modelContext) {
        return;
    }

    function result(text) {
        return { content: [{ type: "text", text: String(text) }] };
    }

    var maxResponseBytes = 4194304;

    function requireInput(input, allowed) {
        if (!input || typeof input !== "object" || Array.isArray(input) ||
            Object.keys(input).some(function (key) { return allowed.indexOf(key) === -1; })) {
            throw new Error("Invalid Cybermaps tool input.");
        }
    }

    function publicUrl(value) {
        if (typeof value !== "string" || value.length > 2048) {
            throw new Error("Invalid public page URL.");
        }
        var url = new URL(value, window.location.href);
        if ((url.protocol !== "https:" && url.protocol !== "http:") || url.username || url.password) {
            throw new Error("Invalid public page URL.");
        }
        url.hash = "";
        return url;
    }

    async function readBody(response, mediaType) {
        if (!response.ok || response.redirected) {
            throw new Error("Cybermaps public request failed.");
        }
        var type = (response.headers.get("Content-Type") || "").split(";")[0].trim().toLowerCase();
        if (type !== mediaType) {
            throw new Error("The requested public representation is unavailable.");
        }
        var length = Number(response.headers.get("Content-Length") || 0);
        if (length > maxResponseBytes || !response.body) {
            throw new Error("The public response cannot be read safely.");
        }
        var reader = response.body.getReader();
        var decoder = new TextDecoder("utf-8", { fatal: true });
        var size = 0;
        var output = "";
        try {
            while (true) {
                var part = await reader.read();
                if (part.done) {
                    return output + decoder.decode();
                }
                size += part.value.byteLength;
                if (size > maxResponseBytes) {
                    throw new Error("The public response exceeds the size limit.");
                }
                output += decoder.decode(part.value, { stream: true });
            }
        } finally {
            await reader.cancel();
        }
    }

    async function fetchPublic(url, mediaType) {
        var controller = new AbortController();
        var timeout = setTimeout(function () { controller.abort(); }, 15000);
        try {
            var response = await fetch(url.toString(), {
                method: "GET",
                credentials: "omit",
                redirect: "error",
                cache: "no-store",
                referrerPolicy: "no-referrer",
                signal: controller.signal,
                headers: { Accept: mediaType }
            });
            return await readBody(response, mediaType);
        } finally {
            clearTimeout(timeout);
        }
    }

    function fetchJson(url) {
        return fetchPublic(url, "application/json").then(function (body) {
            return result(JSON.stringify(JSON.parse(body)));
        });
    }

    function register(tool) {
        try {
            Promise.resolve(modelContext.registerTool(tool)).catch(function () {});
        } catch (error) {
            void error;
        }
    }

    register({
        name: "cybermaps.search_site",
        description: "Search eligible public content published by this site.",
        inputSchema: {
            type: "object",
            properties: {
                query: { type: "string", minLength: 1, maxLength: 200 },
                limit: { type: "integer", minimum: 1, maximum: 20 }
            },
            required: ["query"],
            additionalProperties: false
        },
        execute: async function (input) {
            requireInput(input, ["query", "limit"]);
            if (typeof input.query !== "string" || !input.query.trim() || input.query.length > 200 ||
                (input.limit !== undefined && (!Number.isInteger(input.limit) || input.limit < 1 || input.limit > 20))) {
                throw new Error("Invalid public search parameters.");
            }
            var url = publicUrl(config.searchUrl);
            url.searchParams.set("q", input.query);
            url.searchParams.set("limit", String(input.limit || 10));
            return fetchJson(url);
        }
    });

    register({
        name: "cybermaps.get_page_markdown",
        description: "Read an eligible same-origin page as literal Markdown when available.",
        inputSchema: {
            type: "object",
            properties: { url: { type: "string", format: "uri-reference", maxLength: 2048 } },
            additionalProperties: false
        },
        execute: async function (input) {
            requireInput(input, ["url"]);
            var url = publicUrl(input.url === undefined ? window.location.href : input.url);
            if (url.origin !== window.location.origin) {
                throw new Error("Only same-origin pages can be read.");
            }
            return fetchPublic(url, "text/markdown").then(result);
        }
    });

    register({
        name: "cybermaps.list_discovery_resources",
        description: "List the public Cybermaps discovery resources advertised by this site.",
        inputSchema: { type: "object", properties: {}, additionalProperties: false },
        execute: async function (input) {
            requireInput(input, []);
            return fetchJson(publicUrl(config.discoveryUrl));
        }
    });

}());

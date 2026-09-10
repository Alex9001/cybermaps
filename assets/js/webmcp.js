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

    function readJson(response) {
        if (!response.ok) {
            throw new Error("Cybermaps request failed with HTTP " + response.status + ".");
        }
        return response.json();
    }

    function register(tool) {
        try {
            Promise.resolve(modelContext.registerTool(tool)).catch(function () {});
        } catch (error) {
            void error;
        }
    }

    function appendInput(params, prefix, value) {
        if (Array.isArray(value)) {
            value.forEach(function (item) { appendInput(params, prefix + "[]", item); });
            return;
        }
        if (value && typeof value === "object") {
            Object.keys(value).forEach(function (key) {
                appendInput(params, prefix + "[" + key + "]", value[key]);
            });
            return;
        }
        if (value !== undefined && value !== null) {
            params.append(prefix, String(value));
        }
    }

    function registerPublicAbility(ability) {
        register({
            name: ability.name,
            description: ability.description,
            inputSchema: ability.inputSchema,
            execute: function (input) {
                var url = new URL(ability.runUrl, window.location.href);
                appendInput(url.searchParams, "input", input || {});
                var headers = { Accept: "application/json" };
                if (config.restNonce) {
                    headers["X-WP-Nonce"] = config.restNonce;
                }
                return fetch(url.toString(), { credentials: "same-origin", headers: headers })
                    .then(readJson)
                    .then(function (payload) { return result(JSON.stringify(payload)); });
            }
        });
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
        execute: function (input) {
            var url = new URL(config.searchUrl, window.location.href);
            url.searchParams.set("q", input.query);
            url.searchParams.set("limit", String(input.limit || 10));
            return fetch(url.toString(), { credentials: "same-origin", headers: { Accept: "application/json" } })
                .then(readJson)
                .then(function (payload) { return result(JSON.stringify(payload)); });
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
        execute: function (input) {
            var url = new URL(input.url || window.location.href, window.location.href);
            if (url.origin !== window.location.origin) {
                return Promise.reject(new Error("Only same-origin pages can be read."));
            }
            return fetch(url.toString(), { credentials: "same-origin", headers: { Accept: "text/markdown" } })
                .then(function (response) {
                    if (!response.ok) {
                        throw new Error("Cybermaps request failed with HTTP " + response.status + ".");
                    }
                    return response.text();
                })
                .then(result);
        }
    });

    register({
        name: "cybermaps.list_discovery_resources",
        description: "List the public Cybermaps discovery resources advertised by this site.",
        inputSchema: { type: "object", properties: {}, additionalProperties: false },
        execute: function () {
            return fetch(config.discoveryUrl, { credentials: "same-origin", headers: { Accept: "application/json" } })
                .then(readJson)
                .then(function (payload) { return result(JSON.stringify(payload)); });
        }
    });

    if (Array.isArray(config.abilities)) {
        config.abilities.forEach(registerPublicAbility);
    }
}());

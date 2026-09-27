# Protocol output review — 7.5.3

The September 26 WordPress.org review correctly identified that a generic raw
echo hid the distinction between HTML, JSON and non-HTML output. The HTML and
JSON cases now have separate final sinks: `wp_kses()` with a report-only element
and attribute contract, and `wp_json_encode()` of validated structured JSON.
JSON decoding retains objects (including empty objects), and uses neither
presentation flags nor HTML escaping. Public generators already use the same
compact encoder; response tests compare their bytes with emitted bytes.

The single remaining `OutputNotEscaped` exception is the direct call to
`ProtocolOutput::non_html_document()` inside `emit()`. This private dispatcher
accepts only `xml`, `csv` and `text`; unknown protocols throw. HTML and JSON
cannot enter it. Each selected validator is applied at the echo, rather than
trusting an earlier assignment to a generic output variable.

- XML/RSS producers escape leaf text and attributes (`esc_xml()` or the XML
  serialization helpers). The final validator rejects invalid UTF-8 and DTD /
  entity declarations. HTML-escaping a whole XML document would corrupt its
  markup and invalidate sitemap parsers.
- Markdown, robots, Agent Skills and NDJSON publications use their declared
  non-HTML content types. Their text validator rejects invalid UTF-8 and removes
  NUL bytes; it does not HTML-encode literal Markdown or JSONL.
- CSV producers in `Logs` and `AuditExporter` quote each field, double embedded
  quotes and neutralize formula-leading values before emission. The final sink
  preserves row separators and the UTF-8 BOM.

Public handlers use `Integrity`/publication headers and export handlers set
explicit MIME and `nosniff` headers. GET/HEAD and static/dynamic parity checks
must cover the same bytes used for integrity metadata. These checks are required
before releasing changes to serialization. No generic raw HTML sink, custom
PHPCS escaping-function exemption, or additional output suppression is allowed.

The source gate separately rejects **all** annotation-blind admin HTML output
findings. The security baseline is reduced only for removed suppressions and
findings, with unchanged findings relocated after reviewed source edits. The
remaining non-HTML exception is recorded exactly in `wporg-source-allowlist.json`.

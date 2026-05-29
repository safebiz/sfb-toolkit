#!/usr/bin/env node
// Patch monthly-site-audit.js — adauga sectiunea "Limitari Hosting"

const fs = require("fs");
const file = "/opt/claude-bridge/bin/monthly-site-audit.js";
let src = fs.readFileSync(file, "utf8");

// Backup
fs.writeFileSync(file + ".bak-" + Date.now(), src);

// ── 1. getProjectInfo: citeste SSH_ALIAS, detecteaza hosting SafeBiz ────────
src = src.replace(
  "  return { slug, domain, tier, credPath };\n}",
  [
    "  // Detecteaza daca site-ul e pe hosting SafeBiz (mc-safebiz-1) sau extern",
    "  let sshAlias = '';",
    "  if (fs.existsSync(credFile)) {",
    "    const c = fs.readFileSync(credFile, 'utf8');",
    "    const m = c.match(/^SSH_ALIAS\\s*=\\s*(.+)/m);",
    "    if (m) sshAlias = m[1].trim().replace(/\\r$/, '');",
    "  }",
    "  const onSafebizHosting = sshAlias.startsWith('mc-');",
    "",
    "  return { slug, domain, tier, credPath, sshAlias, onSafebizHosting };",
    "}"
  ].join("\n")
);

// ── 2. Helper constant + function — adauga inainte de generateReport ────────
const HELPER = `
// ── Hosting-Dependent Issues ─────────────────────────────────────────────────
// Probleme care necesita acces direct la server — nu pot fi rezolvate via plugin

const HOSTING_DEPENDENT_HEADERS = {
  'strict-transport-security': 'HSTS necesita configurare la nivel de server web (Apache/LiteSpeed). SafeBiz il configureaza automat pe toate site-urile gazduite.',
  'content-security-policy': 'CSP necesita analiza specifica per site si configurare server. Disponibil in planul hosting SafeBiz.'
};

function getHostingDependentIssues(auditResult) {
  const checks = auditResult.checks || [];
  const found = [];
  const secCheck = checks.find(c => c.check_name === 'security_headers');
  if (secCheck && secCheck.details && secCheck.details.headers) {
    for (const [header, present] of Object.entries(secCheck.details.headers)) {
      if (!present && HOSTING_DEPENDENT_HEADERS[header]) {
        found.push({ header, reason: HOSTING_DEPENDENT_HEADERS[header] });
      }
    }
  }
  return found;
}

`;

src = src.replace("function generateReport(project,", HELPER + "function generateReport(project,");

// ── 3. generateReport MD: sectiunea "Limitari Hosting" dupa Ponturi ─────────
const MD_SECTION = `
  // Sectiunea "Limitari Hosting" — site extern cu probleme server-dependente
  if (!project.onSafebizHosting) {
    const hostingIssues = getHostingDependentIssues(auditResult);
    if (hostingIssues.length > 0) {
      md += '## Limitari Hosting\\n\\n';
      md += 'Urmatoarele probleme identificate **nu pot fi rezolvate** fara acces la configurarea serverului web.\\n';
      md += 'Site-ul nu este gazduit de SafeBiz Solutions. Ne rezervam dreptul de a nu interveni in infrastructura hosting-ului tert.\\n\\n';
      md += '| Problema | Motiv |\\n';
      md += '|---|---|\\n';
      for (const issue of hostingIssues) {
        md += '| ' + issue.header + ' | ' + issue.reason + ' |\\n';
      }
      md += '\\n> **Doriti rezolvare completa?** Migrarea la hosting SafeBiz include configurare server, HSTS, CSP si monitorizare. [safebiz.ro](https://safebiz.ro)\\n\\n';
    }
  }

  // Detail Tables per Category`;

src = src.replace("  // Detail Tables per Category", MD_SECTION);

// ── 4. generateHtmlReport: CSS + card ────────────────────────────────────────
src = src.replace(
  "  .footer {",
  [
    "  .hosting-notice { background: #fff; border: 2px solid #ef4444; border-radius: 12px; padding: 20px 24px; margin: 24px 0; color: #1e293b; }",
    "  .hosting-notice-title { font-size: 1rem; font-weight: 700; color: #ef4444; margin-bottom: 10px; }",
    "  .hosting-notice p { font-size: 0.87rem; color: #334155; margin-bottom: 12px; line-height: 1.5; }",
    "  .hosting-issues-list { list-style: none; padding: 0; margin-bottom: 14px; }",
    "  .hosting-issues-list li { font-size: 0.84rem; padding: 5px 0; color: #334155; border-bottom: 1px solid #e2e8f0; }",
    "  .hosting-issues-list li:last-child { border-bottom: none; }",
    "  .hosting-issues-list code { background: #fef2f2; padding: 1px 7px; border-radius: 4px; font-family: monospace; color: #ef4444; font-size: 0.82rem; }",
    "  .hosting-cta { font-size: 0.87rem; padding: 10px 16px; background: #fef2f2; border-radius: 8px; margin-top: 4px; }",
    "  .hosting-cta a { color: #ef4444; font-weight: 700; text-decoration: none; }",
    "  .footer {"
  ].join("\n  ")
);

// Inlocuieste ${issuesHtml}\n\n${detailHtml} cu versiunea extinsa
// Folosim un placeholder unic pentru a evita probleme de quoting
const OLD_BODY = '${issuesHtml}\n\n${detailHtml}';
const NEW_BODY = `\${issuesHtml}

\${(function() {
  if (project.onSafebizHosting) return '';
  var hIssues = getHostingDependentIssues(auditResult);
  if (!hIssues.length) return '';
  var items = hIssues.map(function(i) {
    return '<li><code>' + esc(i.header) + '</code> — ' + esc(i.reason) + '</li>';
  }).join('');
  return '<div class="hosting-notice">'
    + '<div class="hosting-notice-title">Limitari Hosting — Probleme nerezolvabile pe hosting extern</div>'
    + '<p>Urmatoarele probleme au fost identificate dar <strong>nu pot fi rezolvate</strong> fara acces la configurarea serverului web. '
    + 'Site-ul nu este gazduit de SafeBiz Solutions, prin urmare ne rezervam dreptul de a nu interveni in infrastructura hosting-ului tert.</p>'
    + '<ul class="hosting-issues-list">' + items + '</ul>'
    + '<div class="hosting-cta">Doriti rezolvare completa? <a href="https://safebiz.ro" target="_blank">Migrati la hosting SafeBiz Solutions &#8594;</a></div>'
    + '</div>';
}())}

\${detailHtml}`;

if (src.includes(OLD_BODY)) {
  src = src.replace(OLD_BODY, NEW_BODY);
  console.log("  ✓ issuesHtml/detailHtml replaced");
} else {
  console.log("  ✗ WARNING: issuesHtml pattern not found — check manually");
}

fs.writeFileSync(file, src);
console.log("PATCH OK — " + file + " (" + src.length + " chars)");

function setStatus(message, isError = false) {
  const el = document.getElementById("status");
  el.textContent = message;
  el.className = isError ? "status err" : "status";
}

function setRaw(data) {
  document.getElementById("raw-json").textContent = JSON.stringify(data, null, 2);
}

function fitLabel(score) {
  if (score >= 0.8) return { text: "Phu hop cao", cls: "ok" };
  if (score >= 0.6) return { text: "Phu hop trung binh", cls: "warn" };
  return { text: "Chua phu hop", cls: "err" };
}

async function fetchJson(url, options) {
  const res = await fetch(url, options);
  const raw = await res.text();
  let payload = null;

  try {
    payload = raw ? JSON.parse(raw) : {};
  } catch {
    payload = { detail: raw };
  }

  if (!res.ok) {
    throw new Error(typeof payload === "string" ? payload : JSON.stringify(payload));
  }
  return payload;
}

async function callSingleApi(file, jdText) {
  const form = new FormData();
  form.append("file", file);
  form.append("jd_text", jdText);
  return fetchJson("/screen", { method: "POST", body: form });
}

async function callBatchApi(files, jdText, topK) {
  const form = new FormData();
  files.forEach((file) => form.append("files", file));
  form.append("jd_text", jdText);
  form.append("top_k", String(topK));
  form.append("analysis_mode", "lite");
  form.append("embedding_budget", "32");
  return fetchJson("/screen/batch", { method: "POST", body: form });
}

function renderCandidatePanel(candidate) {
  const scoreEl = document.getElementById("score");
  const fitEl = document.getElementById("fit");
  const metaEl = document.getElementById("candidate-meta");
  const projectMetaEl = document.getElementById("project-score-meta");
  const linkModeEl = document.getElementById("link-mode-meta");
  const linksListEl = document.getElementById("project-links-list");

  const scores = candidate.scores || {};
  const analysis = candidate.analysis || {};
  const productLinkReport = analysis.product_link_report || {};
  const projectFitReport = analysis.project_fit_report || {};

  const finalScore = Number(scores.final_score || 0);
  const percent = Math.round(finalScore * 10000) / 100;
  const label = fitLabel(finalScore);

  scoreEl.textContent = `${percent}%`;
  fitEl.textContent = label.text;
  fitEl.className = `fit ${label.cls}`;

  const name = candidate.candidate_name || "Unknown";
  const email = candidate.email || "N/A";
  metaEl.textContent = `Candidate: ${name} | Email: ${email} | File: ${candidate.filename || "N/A"}`;

  if (scores.project_score === null || scores.project_score === undefined) {
    projectMetaEl.textContent = "Project fit score: N/A";
  } else {
    projectMetaEl.textContent = `Project fit score: ${Math.round(Number(scores.project_score) * 10000) / 100}%`;
  }

  linkModeEl.textContent =
    `Link detection mode: ${productLinkReport.detection_mode || "unknown"} | ` +
    `Total links found: ${Number(productLinkReport.total_links_found || 0)} | ` +
    `Project snippets found: ${Number(projectFitReport.project_snippets_found || 0)}`;

  const links = Array.isArray(productLinkReport.links) ? productLinkReport.links : [];
  if (links.length === 0) {
    linksListEl.innerHTML = "<li>Khong tim thay project link theo tieu chi hien tai.</li>";
  } else {
    linksListEl.innerHTML = links
      .map((item) => {
        const source = item.source || "text";
        const page = item.page ? `page ${item.page}` : "page N/A";
        let reachable = "n/a";
        if (item.reachable === true) reachable = "ok";
        if (item.reachable === false) reachable = "error";
        return (
          `<li><a href="${item.final_url || item.url}" target="_blank" rel="noreferrer">${item.url}</a>` +
          `<span class="pill">${source}</span>` +
          `<span class="pill">${page}</span>` +
          `<span class="pill">${reachable}</span></li>`
        );
      })
      .join("");
  }
}

function renderBatchRanking(topCandidates, strategy) {
  const rankEl = document.getElementById("batch-ranking-list");
  if (!Array.isArray(topCandidates) || topCandidates.length === 0) {
    rankEl.innerHTML = "<li>Khong co ket qua batch.</li>";
    return;
  }

  const strategyText =
    strategy && strategy.mode
      ? ` (${strategy.mode}, embedded=${strategy.embedded_candidates || 0}/${strategy.prefiltered_candidates || 0})`
      : "";

  rankEl.innerHTML = topCandidates
    .map((c, i) => {
      const score = Math.round(Number(c.scores?.final_score || 0) * 10000) / 100;
      return `<li>#${i + 1} ${c.candidate_name || "Unknown"} - ${score}% - ${c.filename || ""}</li>`;
    })
    .join("");

  if (strategyText) {
    rankEl.innerHTML = `<li>Batch strategy${strategyText}</li>` + rankEl.innerHTML;
  }
}

function renderResult(data) {
  const resultEl = document.getElementById("result");

  if (data.candidate) {
    renderCandidatePanel(data.candidate);
    renderBatchRanking([], null);
  } else if (Array.isArray(data.top_candidates)) {
    const bestCandidate = data.top_candidates[0];
    if (bestCandidate) {
      renderCandidatePanel(bestCandidate);
    }
    renderBatchRanking(data.top_candidates, data.batch_strategy || null);
  }

  resultEl.hidden = false;
  setRaw(data);
}

document.getElementById("btn-check").addEventListener("click", async () => {
  const button = document.getElementById("btn-check");

  try {
    const fileInput = document.getElementById("cv-file");
    const files = Array.from(fileInput.files || []);
    const jdText = document.getElementById("jd-text").value.trim();
    const topK = Number(document.getElementById("top-k").value || 10);

    if (files.length === 0) throw new Error("Vui long chon it nhat 1 file CV (.pdf/.docx).");
    if (!jdText) throw new Error("Vui long nhap JD.");

    button.disabled = true;
    if (files.length === 1) {
      setStatus("Dang cham diem 1 CV...");
      const data = await callSingleApi(files[0], jdText);
      renderResult(data);
      setStatus("Cham diem thanh cong.");
    } else {
      setStatus(`Dang cham ${files.length} CV (batch mode)...`);
      const data = await callBatchApi(files, jdText, topK);
      renderResult(data);
      setStatus(`Cham batch thanh cong: ${data.total_success}/${data.total_uploaded} CV.`);
    }
  } catch (err) {
    setStatus(`Loi: ${err.message}`, true);
  } finally {
    button.disabled = false;
  }
});

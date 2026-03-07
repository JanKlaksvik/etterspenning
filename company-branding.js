(function initCompanyBranding(global){
  "use strict";

  const AUTH_KEY = "esp_pm_auth_v1";
  const BRAND_PREFIX = "esp_company_brand_v1__";
  const DEFAULT_LOGO = "Logo.jpeg";

  function safeParse(raw){
    if (!raw) return null;
    try {
      return JSON.parse(raw);
    } catch (err) {
      return null;
    }
  }

  function getSession(){
    const parsed = safeParse(localStorage.getItem(AUTH_KEY));
    if (!parsed || typeof parsed !== "object") return null;
    let expiresAt = Number(parsed.expiresAt);
    if (!Number.isFinite(expiresAt) && typeof parsed.expiresAt === "string") {
      const fromDate = Date.parse(parsed.expiresAt);
      expiresAt = Number.isFinite(fromDate) ? fromDate : NaN;
    }
    if (!Number.isFinite(expiresAt) || expiresAt < Date.now()) return null;
    return parsed;
  }

  function companyIdFromSession(session){
    const raw = session && session.companyId !== undefined && session.companyId !== null
      ? String(session.companyId).trim()
      : "";
    return raw || "no_company";
  }

  function companyKey(companyId){
    const normalized = (companyId ?? "").toString().trim() || "no_company";
    return BRAND_PREFIX + normalized;
  }

  function normalizeBranding(value){
    if (!value || typeof value !== "object") {
      return { companyName: "", logoSrc: "", updatedAt: "" };
    }
    return {
      companyName: (value.companyName || "").toString().trim(),
      logoSrc: (value.logoSrc || "").toString(),
      updatedAt: (value.updatedAt || "").toString()
    };
  }

  function getBranding(options){
    const opts = options || {};
    const session = opts.session || getSession();
    const companyId = (opts.companyId ?? companyIdFromSession(session)).toString().trim() || "no_company";
    const stored = normalizeBranding(safeParse(localStorage.getItem(companyKey(companyId))));
    if (!stored.companyName && session && session.companyName) {
      stored.companyName = String(session.companyName).trim();
    }
    if (!stored.logoSrc && session && session.companyLogo) {
      stored.logoSrc = String(session.companyLogo);
    }
    return { companyId, ...stored };
  }

  function saveBranding(options){
    const opts = options || {};
    const session = opts.session || getSession();
    const companyId = (opts.companyId ?? companyIdFromSession(session)).toString().trim() || "no_company";
    const existing = normalizeBranding(safeParse(localStorage.getItem(companyKey(companyId))));

    const next = {
      companyName: opts.companyName !== undefined
        ? (opts.companyName || "").toString().trim()
        : existing.companyName,
      logoSrc: opts.logoSrc !== undefined
        ? (opts.logoSrc || "").toString()
        : existing.logoSrc,
      updatedAt: new Date().toISOString()
    };

    localStorage.setItem(companyKey(companyId), JSON.stringify(next));
    return { companyId, ...next };
  }

  function userLabelFromSession(session){
    if (!session || typeof session !== "object") return "";
    const fullName = (session.fullName || session.name || "").toString().trim();
    const email = (session.email || "").toString().trim();
    return fullName || email || "";
  }

  function apply(options){
    const opts = options || {};
    const session = opts.session || getSession();
    const branding = getBranding({
      session,
      companyId: opts.companyId
    });

    const companyName = (opts.companyName || "").toString().trim()
      || (session && session.companyName ? String(session.companyName).trim() : "")
      || branding.companyName
      || "-";
    const userName = (opts.userName || "").toString().trim()
      || userLabelFromSession(session)
      || "-";
    const sessionHasLogoField = !!(
      session &&
      Object.prototype.hasOwnProperty.call(session, "companyLogo")
    );
    const sessionLogo = sessionHasLogoField
      ? String(session.companyLogo || "").trim()
      : "";
    const forcedLogo = (opts.logoSrc || "").toString().trim();
    let logoSrc = DEFAULT_LOGO;
    if (forcedLogo) {
      logoSrc = forcedLogo;
    } else if (sessionHasLogoField) {
      logoSrc = sessionLogo || DEFAULT_LOGO;
    } else if (branding.logoSrc) {
      logoSrc = branding.logoSrc;
    }

    const root = opts.root && opts.root.querySelectorAll ? opts.root : document;

    root.querySelectorAll("[data-company-logo]").forEach((el) => {
      if (!(el instanceof HTMLImageElement)) return;
      el.src = logoSrc;
      el.alt = (companyName === "-" ? "Firma" : companyName) + " logo";
      el.decoding = "async";
      el.loading = "lazy";
    });

    root.querySelectorAll("[data-company-name]").forEach((el) => {
      el.textContent = companyName;
    });

    root.querySelectorAll("[data-user-name]").forEach((el) => {
      el.textContent = userName;
    });

    root.querySelectorAll("[data-company-identity]").forEach((el) => {
      el.textContent = "Innlogget: " + userName + " • Firma: " + companyName;
    });

    if (session && sessionHasLogoField && sessionLogo === "" && branding.logoSrc !== "") {
      saveBranding({
        session,
        companyId: branding.companyId,
        companyName,
        logoSrc: ""
      });
    } else if (session && logoSrc && logoSrc !== DEFAULT_LOGO && logoSrc !== branding.logoSrc) {
      saveBranding({
        session,
        companyId: branding.companyId,
        companyName,
        logoSrc
      });
    }

    return {
      session,
      companyId: branding.companyId,
      companyName,
      userName,
      logoSrc
    };
  }

  function readFileAsDataUrl(file){
    return new Promise((resolve, reject) => {
      const reader = new FileReader();
      reader.onload = () => {
        resolve(typeof reader.result === "string" ? reader.result : "");
      };
      reader.onerror = () => reject(new Error("Kunne ikke lese bildefilen."));
      reader.readAsDataURL(file);
    });
  }

  async function saveLogoFromFileInput(input, options){
    const opts = options || {};
    if (!input || !input.files || input.files.length === 0) {
      return { ok: false, message: "Velg en logo-fil for opplasting." };
    }

    const file = input.files[0];
    const maxBytes = Number(opts.maxBytes) > 0 ? Number(opts.maxBytes) : (2 * 1024 * 1024);

    if (!/^image\//.test(file.type || "")) {
      return { ok: false, message: "Filtype ma vare bilde (PNG, JPG, WebP, SVG)." };
    }
    if (file.size > maxBytes) {
      return { ok: false, message: "Logoen er for stor. Maks 2 MB." };
    }

    let dataUrl = "";
    try {
      dataUrl = await readFileAsDataUrl(file);
    } catch (err) {
      return { ok: false, message: err && err.message ? err.message : "Kunne ikke lese bildefilen." };
    }
    if (!dataUrl) {
      return { ok: false, message: "Kunne ikke lese bildefilen." };
    }

    const session = opts.session || getSession();
    const companyName = (opts.companyName || "").toString().trim()
      || (session && session.companyName ? String(session.companyName).trim() : "");

    const saved = saveBranding({
      session,
      companyId: opts.companyId,
      companyName,
      logoSrc: dataUrl
    });

    apply({
      session,
      companyId: saved.companyId,
      companyName
    });

    return {
      ok: true,
      message: "Firmalogo lagret.",
      branding: saved
    };
  }

  function clearLogo(options){
    const opts = options || {};
    const session = opts.session || getSession();
    const companyName = (opts.companyName || "").toString().trim()
      || (session && session.companyName ? String(session.companyName).trim() : "");

    const saved = saveBranding({
      session,
      companyId: opts.companyId,
      companyName,
      logoSrc: ""
    });

    apply({
      session,
      companyId: saved.companyId,
      companyName
    });

    return {
      ok: true,
      message: "Firmalogo fjernet.",
      branding: saved
    };
  }

  global.CompanyBranding = {
    getSession,
    getBranding,
    saveBranding,
    apply,
    saveLogoFromFileInput,
    clearLogo
  };
})(window);

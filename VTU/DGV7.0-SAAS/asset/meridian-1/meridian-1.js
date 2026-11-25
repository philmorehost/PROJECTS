(function () {
  var cfg = window.MERIDIAN_CONFIG || {};
  var siteName = String(cfg.siteName || "FintechFlow");
  var appUrl = String(cfg.appUrl || "");
  var downloadPageUrl = String(cfg.downloadPageUrl || "/DownloadApp.php");
  var businessUrl = String(cfg.businessUrl || "/Business.php");
  var personalUrl = String(cfg.personalUrl || "/");
  var registerUrl = String(cfg.registerUrl || "/web/Register.php");
  var loginUrl = String(cfg.loginUrl || "/web/Login.php");

  function closeMenu() {
    var menu = document.getElementById("sheet");
    var menuButton = document.getElementById("menuBtn");
    if (menu) menu.hidden = true;
    if (menuButton) menuButton.setAttribute("aria-expanded", "false");
  }

  function openMenu() {
    var menu = document.getElementById("sheet");
    var menuButton = document.getElementById("menuBtn");
    if (menu) {
      menu.hidden = false;
      if (menuButton) menuButton.setAttribute("aria-expanded", "true");
    }
  }

  function closeGetSheet() {
    var sheet = document.getElementById("getSheet");
    if (sheet) sheet.hidden = true;
  }

  function openGetSheet() {
    var sheet = document.getElementById("getSheet");
    var body = document.getElementById("getBody");
    if (!sheet || !body) {
      window.location.href = appUrl || downloadPageUrl;
      return;
    }
    body.textContent = "";

    var topRow = document.createElement("div");
    topRow.style.cssText = "display:flex;justify-content:space-between;align-items:center;gap:12px;margin-bottom:14px";
    var title = document.createElement("h3");
    title.id = "getTitle";
    title.style.margin = "0";
    title.textContent = "Get the " + siteName + " app";
    var closeBtn = document.createElement("button");
    closeBtn.type = "button";
    closeBtn.className = "icon-btn";
    closeBtn.setAttribute("aria-label", "Close");
    closeBtn.textContent = "×";
    closeBtn.style.fontSize = "22px";
    closeBtn.addEventListener("click", closeGetSheet);
    topRow.appendChild(title);
    topRow.appendChild(closeBtn);
    body.appendChild(topRow);

    var desc = document.createElement("p");
    desc.className = "small";
    desc.style.margin = "0 0 18px";
    desc.textContent = appUrl
      ? "Download the official mobile app or continue directly in your browser."
      : "No app package URL is published yet. You can create an account or sign in on the web right away.";
    body.appendChild(desc);

    var actions = document.createElement("div");
    actions.style.cssText = "display:flex;flex-direction:column;gap:10px";

    if (appUrl) {
      var dl = document.createElement("a");
      dl.className = "btn primary";
      dl.href = appUrl;
      dl.rel = "noopener noreferrer";
      dl.textContent = "Download App";
      actions.appendChild(dl);
    }

    var dlInfo = document.createElement("a");
    dlInfo.className = appUrl ? "btn ghost" : "btn primary";
    dlInfo.href = downloadPageUrl;
    dlInfo.textContent = appUrl ? "App download page & details" : "Continue to App Info";
    actions.appendChild(dlInfo);

    var webBtn = document.createElement("a");
    webBtn.className = "btn ghost";
    webBtn.href = registerUrl;
    webBtn.textContent = "Create free web account";
    actions.appendChild(webBtn);

    var signBtn = document.createElement("a");
    signBtn.className = "btn ghost";
    signBtn.href = loginUrl;
    signBtn.textContent = "Log in to web";
    actions.appendChild(signBtn);

    body.appendChild(actions);
    sheet.hidden = false;
  }

  function handleWorld(button) {
    var world = button.getAttribute("data-w");
    if (world !== "personal" && world !== "business") return;
    window.location.href = world === "business" ? businessUrl : personalUrl;
  }

  document.addEventListener("DOMContentLoaded", function () {
    var world = document.documentElement.dataset.world;
    document.querySelectorAll('#pswitch [role="radio"][data-w]').forEach(function (choice) {
      var selected = choice.dataset.w === world;
      choice.setAttribute("aria-checked", String(selected));
      choice.tabIndex = selected ? 0 : -1;
    });

    // Header compact pill on scroll
    var nav = document.getElementById("nav");
    if (nav) {
      var syncNav = function () {
        nav.classList.toggle("compact", window.scrollY > 18);
      };
      syncNav();
      window.addEventListener("scroll", syncNav, { passive: true });
    }

    // Reveal animations
    var rvNodes = document.querySelectorAll("[data-rv]");
    if (rvNodes.length) {
      if ("IntersectionObserver" in window) {
        var obs = new IntersectionObserver(function (entries) {
          entries.forEach(function (entry) {
            if (entry.isIntersecting) {
              entry.target.classList.add("in");
              obs.unobserve(entry.target);
            }
          });
        }, { threshold: 0.08 });
        rvNodes.forEach(function (n) { obs.observe(n); });
      } else {
        rvNodes.forEach(function (n) { n.classList.add("in"); });
      }
    }

    // Card 3D flip
    var flipBtn = document.getElementById("flipBtn");
    var card3d = document.getElementById("card3d");
    if (flipBtn && card3d) {
      flipBtn.addEventListener("click", function () {
        var flipped = card3d.classList.toggle("is-flipped");
        flipBtn.textContent = flipped ? "Show front" : "Flip the card";
      });
    }

    // Vault jar interactive preview
    var vaultBtn = document.getElementById("vaultBtn");
    var vaultJar = document.getElementById("jar");
    var vaultBal = document.getElementById("jarAmt");
    if (vaultBtn && vaultJar && vaultBal) {
      var vaultSaved = 62000;
      var vaultTarget = 400000;
      vaultBtn.addEventListener("click", function () {
        vaultSaved += 50000;
        if (vaultSaved > vaultTarget) vaultSaved = 62000;
        var pct = Math.min(0.92, Math.max(0.24, vaultSaved / vaultTarget));
        vaultJar.style.setProperty("--fill", pct.toFixed(2));
        vaultBal.textContent = "₦" + vaultSaved.toLocaleString("en-NG");
      });
    }

    // Business chapter rail switcher
    var bstory = document.getElementById("bstory");
    if (bstory) {
      var railBtns = Array.prototype.slice.call(bstory.querySelectorAll(".bs-rail button"));
      var chapters = Array.prototype.slice.call(bstory.querySelectorAll(".bs-ch"));
      var activateChapter = function (idx) {
        chapters.forEach(function (ch, i) {
          ch.classList.toggle("is-active", i === idx);
        });
        railBtns.forEach(function (btn, i) {
          if (i === idx) btn.setAttribute("aria-current", "step");
          else btn.removeAttribute("aria-current");
        });
      };
      if (chapters.length && railBtns.length) {
        activateChapter(0);
        railBtns.forEach(function (btn, idx) {
          btn.addEventListener("click", function () {
            activateChapter(idx);
          });
        });
      }
    }
  });

  document.addEventListener("click", function (event) {
    var target = event.target;
    if (!(target instanceof Element)) return;

    var worldButton = target.closest('[role="radio"][data-w]');
    if (worldButton) {
      event.preventDefault();
      handleWorld(worldButton);
      return;
    }

    var menuButton = target.closest("#menuBtn");
    if (menuButton) {
      event.preventDefault();
      var menu = document.getElementById("sheet");
      if (menu && menu.hidden) openMenu();
      else closeMenu();
      return;
    }

    if (target.closest("#menuClose") || (target.id === "sheet")) {
      closeMenu();
      return;
    }

    var openGet = target.closest("[data-open-get]");
    if (openGet) {
      event.preventDefault();
      openGetSheet();
      return;
    }

    if (target.id === "getSheet") {
      closeGetSheet();
      return;
    }

    var menuLink = target.closest("#sheet a[href]");
    if (menuLink) {
      closeMenu();
    }
  });

  document.addEventListener("keydown", function (event) {
    if (event.key === "Escape") {
      closeMenu();
      closeGetSheet();
    }
  });
})();

/* Dual-app interactive preview */
(function () {
  var section = document.getElementById("duo-ch");
  if (!section) return;

  var cfg = window.MERIDIAN_CONFIG || {};
  var brandName = String(cfg.siteName || "FintechFlow");

  var phoneCornerStyle = document.createElement("style");
  phoneCornerStyle.textContent =
    '.duo-dev[data-open="false"] .duo-r .duo-shell{border-radius:6cqi}';
  document.head.appendChild(phoneCornerStyle);

  var device = section.querySelector("#duoDev");
  var leftScreen = section.querySelector("#duoL");
  var rightScreen = section.querySelector("#duoR");
  var cover = section.querySelector("#duoCover");
  var toggle = section.querySelector("#duoToggle");
  var steps = Array.prototype.slice.call(section.querySelectorAll(".duo-steps button[data-app]"));
  var leftHalf = section.querySelector(".duo-l");
  if (!device || !leftScreen || !rightScreen || !cover || !toggle || !leftHalf) return;

  var state = { app: "personal", open: false, paid: false, receipt: false, busy: false };
  var formatMoney = function (amount) { return "₦" + amount.toLocaleString("en-NG"); };
  var brands = { personal: brandName, business: brandName + " Business" };
  var checkmark = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12.5l4.5 4.5L19 7.5"/></svg>';

  function esc(str) {
    return String(str).replace(/[&<>"']/g, function (c) {
      return ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" })[c];
    });
  }

  function actionButton(action, label, alternate) {
    return '<button type="button" class="dz-btn' + (alternate ? " alt" : "") + '" data-act="' + action + '"' + (state.busy ? " disabled" : "") + ">" + label + "</button>";
  }

  function screenBar(app) {
    return '<div class="dz-bar"><span>9:41</span><b>' + esc(brands[app]) + "</b></div>";
  }

  function renderPersonal() {
    return [
      screenBar("personal") +
        '<div class="dz-body"><span class="dz-lbl">Personal Wallet</span><span class="dz-small">Balance</span><b class="dz-big">' +
        formatMoney(state.paid ? 95000 : 120000) +
        '</b><div class="dz-list"><span class="dz-small">Recent</span>' +
        (state.paid ? '<div class="dz-row dz-new"><i>AT</i><span>Ada’s Tailoring</span><b>−' + formatMoney(25000) + "</b></div>" : "") +
        '<div class="dz-row"><i>MT</i><span>Data top-up</span><b>−' + formatMoney(2000) + "</b></div></div></div>",
      screenBar("personal") +
        '<div class="dz-body dz-c"><i class="dz-av">AT</i><b class="dz-name">Ada’s Tailoring</b><span class="dz-small">Invoice #0142</span><b class="dz-big">' +
        formatMoney(25000) + "</b>" +
        (state.paid
          ? '<div class="dz-done">' + checkmark + '<span>Paid</span></div>' + actionButton("go-business", "See it in Business →", true)
          : actionButton("pay", state.busy ? "Paying…" : "Pay " + formatMoney(25000))) +
        "</div>"
    ];
  }

  function renderBusiness() {
    return [
      screenBar("business") +
        '<div class="dz-body"><span class="dz-lbl">Ada’s Tailoring</span><span class="dz-small">Business balance</span><b class="dz-big">' +
        formatMoney(state.paid ? 505000 : 480000) +
        '</b><div class="dz-list"><span class="dz-small">Incoming</span>' +
        (state.paid ? '<div class="dz-row dz-new"><i>FA</i><span>Funmi A.</span><b class="dz-in">+' + formatMoney(25000) + "</b></div>" : '<div class="dz-row dz-wait"><span>Waiting for payment</span></div>') +
        "</div></div>",
      screenBar("business") +
        '<div class="dz-body dz-c"><span class="dz-small">Invoice #0142</span><b class="dz-name">Funmi A.</b><b class="dz-big">' +
        formatMoney(25000) + '</b><span class="dz-pill ' + (state.paid ? "ok" : "") + '">' + (state.paid ? "Paid" : "Unpaid") + "</span>" +
        (state.paid
          ? (state.receipt ? '<div class="dz-done">' + checkmark + '<span>Receipt sent</span></div>' + actionButton("restart", "Start again", true) : actionButton("receipt", state.busy ? "Sending…" : "Send receipt"))
          : actionButton("go-personal", "Pay in Personal →", true)) +
        "</div>"
    ];
  }

  function render() {
    var screens = (state.app === "business" ? renderBusiness : renderPersonal)();
    device.dataset.app = state.app;
    leftScreen.innerHTML = screens[0];
    rightScreen.innerHTML = screens[1];
    steps.forEach(function (step) {
      if (step.dataset.app === state.app) step.setAttribute("aria-current", "step");
      else step.removeAttribute("aria-current");
    });
  }

  function setOpen(open) {
    state.open = open;
    device.dataset.open = String(open);
    device.style.setProperty("--a", open ? "0deg" : "180deg");
    device.style.setProperty("--c", open ? "0" : "1");
    device.style.transition = "translate 800ms cubic-bezier(.2,.8,.2,1)";
    leftHalf.style.transition = "transform 800ms cubic-bezier(.2,.8,.2,1)";
    toggle.textContent = open ? "Close the phone" : "Open the phone";
    toggle.setAttribute("aria-expanded", String(open));
    leftScreen.inert = !open;
    rightScreen.inert = !open;
    cover.tabIndex = open ? -1 : 0;
  }

  function selectApp(app) {
    if (!brands[app]) return;
    state.app = app;
    if (!state.open) setOpen(true);
    render();
  }

  function runAction(action) {
    if (state.busy) return;
    var finish = function (update) {
      state.busy = true;
      render();
      window.setTimeout(function () {
        state.busy = false;
        update();
        render();
      }, 450);
    };
    if (action === "pay") finish(function () { state.paid = true; });
    else if (action === "receipt") finish(function () { state.receipt = true; });
    else if (action === "restart") { state.app = "personal"; state.paid = false; state.receipt = false; render(); }
    else if (action.indexOf("go-") === 0) selectApp(action.slice(3));
  }

  section.addEventListener("click", function (event) {
    var step = event.target.closest(".duo-steps button[data-app]");
    if (step) { selectApp(step.dataset.app); return; }
    if (event.target.closest("#duoToggle")) { setOpen(!state.open); return; }
    if (event.target.closest("#duoCover")) { setOpen(true); return; }
    var action = event.target.closest("[data-act]");
    if (action && !action.disabled) runAction(action.dataset.act);
  });

  render();
  setOpen(false);
})();

/* Interactive Globe Transfer Preview (uses live CSS --accent) */
(function () {
  var section = document.getElementById("send-ch");
  if (!section) return;

  var destinations = [
    { id: "london", city: "London", country: "United Kingdom", flag: "gb", point: [0.04, -0.38] },
    { id: "new-york", city: "New York", country: "United States", flag: "us", point: [-0.38, -0.08] },
    { id: "toronto", city: "Toronto", country: "Canada", flag: "ca", point: [-0.3, -0.3] },
    { id: "paris", city: "Paris", country: "France", flag: "fr", point: [0.14, -0.29] },
    { id: "johannesburg", city: "Johannesburg", country: "South Africa", flag: "za", point: [0.08, 0.42] }
  ];
  var origin = { city: "Lagos", point: [0.02, 0.28] };
  var chips = section.querySelector("#destChips");
  var globe = section.querySelector("#homeGlobe");
  var canvas = globe && globe.querySelector("canvas");
  var label = section.querySelector("#globeLabel");
  var sendButton = section.querySelector("#sendBtn");
  var status = section.querySelector("#sendStatus");
  var infoButton = section.querySelector("#transferInfoBtn");
  var info = section.querySelector("#transferInfo");
  if (!chips || !canvas || !label || !sendButton || !status || !infoButton || !info) return;

  var context = canvas.getContext("2d");
  if (!context) return;

  var destinationsById = {};
  destinations.forEach(function (d) { destinationsById[d.id] = d; });
  var selected = destinations[0];
  var routeProgress = 1;
  var sending = false;

  chips.innerHTML = destinations.map(function (destination) {
    return '<button class="chip" type="button" data-destination="' + destination.id + '" aria-pressed="' + (destination.id === selected.id) + '" aria-label="' + destination.city + ', ' + destination.country + '"><svg class="flag" aria-hidden="true" focusable="false"><use href="#fl-' + destination.flag + '"></use></svg>' + destination.city + "</button>";
  }).join("");

  function getAccent() {
    var cs = getComputedStyle(document.documentElement);
    return (cs.getPropertyValue("--accent") || "#287BFF").trim() || "#287BFF";
  }

  function setLabel(destination) {
    label.innerHTML = '<svg class="flag" aria-hidden="true" focusable="false"><use href="#fl-ng"></use></svg> ' + origin.city + ' to <svg class="flag" aria-hidden="true" focusable="false"><use href="#fl-' + destination.flag + '"></use></svg> ' + destination.city;
    sendButton.textContent = "Preview ₦20,000 to " + destination.city;
  }

  function resizeCanvas() {
    var bounds = canvas.getBoundingClientRect();
    if (!bounds.width || !bounds.height) return;
    var ratio = Math.min(window.devicePixelRatio || 1, 2);
    canvas.width = Math.round(bounds.width * ratio);
    canvas.height = Math.round(bounds.height * ratio);
    context.setTransform(ratio, 0, 0, ratio, 0, 0);
    drawGlobe();
  }

  function drawGlobe() {
    var width = canvas.clientWidth;
    var height = canvas.clientHeight;
    if (!width || !height) return;
    var accent = getAccent();
    var radius = Math.min(width, height) * 0.46;
    var centerX = width / 2;
    var centerY = height / 2;
    context.clearRect(0, 0, width, height);

    var sphere = context.createRadialGradient(centerX - radius * 0.34, centerY - radius * 0.42, radius * 0.08, centerX, centerY, radius * 1.12);
    sphere.addColorStop(0, "#243b6b");
    sphere.addColorStop(0.7, "#111833");
    sphere.addColorStop(1, "#080b19");
    context.beginPath();
    context.arc(centerX, centerY, radius, 0, Math.PI * 2);
    context.fillStyle = sphere;
    context.fill();

    context.save();
    context.beginPath();
    context.arc(centerX, centerY, radius - 1, 0, Math.PI * 2);
    context.clip();

    for (var latitude = -80; latitude <= 80; latitude += 8) {
      var lat = (latitude * Math.PI) / 180;
      for (var longitude = -88; longitude <= 88; longitude += 8) {
        var lon = (longitude * Math.PI) / 180;
        var depth = Math.cos(lat) * Math.cos(lon);
        var x = centerX + radius * Math.cos(lat) * Math.sin(lon);
        var y = centerY - radius * Math.sin(lat);
        var size = 0.65 + Math.max(0, depth) * 0.72;
        context.beginPath();
        context.arc(x, y, size, 0, Math.PI * 2);
        context.fillStyle = "rgba(226,232,240," + (0.24 + Math.max(0, depth) * 0.46) + ")";
        context.fill();
      }
    }

    var pt = function (coords) { return { x: centerX + coords[0] * radius, y: centerY + coords[1] * radius }; };
    var start = pt(origin.point);
    var end = pt(selected.point);
    var control = { x: (start.x + end.x) / 2 - radius * 0.2, y: (start.y + end.y) / 2 - radius * 0.18 };

    context.beginPath();
    context.moveTo(start.x, start.y);
    context.quadraticCurveTo(control.x, control.y, end.x, end.y);
    context.strokeStyle = "rgba(148,163,184,.38)";
    context.lineWidth = 3;
    context.stroke();

    context.beginPath();
    context.moveTo(start.x, start.y);
    var progress = Math.max(0, Math.min(1, routeProgress));
    var routeEnd = {
      x: Math.pow(1 - progress, 2) * start.x + 2 * (1 - progress) * progress * control.x + Math.pow(progress, 2) * end.x,
      y: Math.pow(1 - progress, 2) * start.y + 2 * (1 - progress) * progress * control.y + Math.pow(progress, 2) * end.y
    };
    var routeControl = { x: start.x + (control.x - start.x) * progress, y: start.y + (control.y - start.y) * progress };
    context.quadraticCurveTo(routeControl.x, routeControl.y, routeEnd.x, routeEnd.y);
    context.strokeStyle = accent;
    context.lineWidth = 2.5;
    context.shadowColor = accent;
    context.shadowBlur = 10;
    context.stroke();
    context.shadowBlur = 0;

    var drawMarker = function (loc, title, active) {
      context.beginPath();
      context.arc(loc.x, loc.y, active ? 9 : 6, 0, Math.PI * 2);
      context.fillStyle = "#fff";
      context.fill();
      context.beginPath();
      context.arc(loc.x, loc.y, active ? 4 : 2.5, 0, Math.PI * 2);
      context.fillStyle = accent;
      context.fill();
      context.font = "600 14px system-ui, sans-serif";
      context.fillStyle = "#fff";
      context.textBaseline = "middle";
      context.fillText(title, loc.x + 12, loc.y);
    };
    drawMarker(start, origin.city, false);
    drawMarker(end, selected.city, true);
    context.restore();

    context.beginPath();
    context.arc(centerX, centerY, radius, 0, Math.PI * 2);
    context.strokeStyle = "rgba(226,232,240,.28)";
    context.lineWidth = 1;
    context.stroke();
  }

  function selectDestination(destination) {
    selected = destination;
    routeProgress = 1;
    status.textContent = "";
    delete status.dataset.state;
    chips.querySelectorAll("button[data-destination]").forEach(function (button) {
      button.setAttribute("aria-pressed", String(button.dataset.destination === destination.id));
    });
    setLabel(destination);
    drawGlobe();
  }

  chips.addEventListener("click", function (event) {
    var button = event.target.closest("button[data-destination]");
    if (!button || sending) return;
    if (destinationsById[button.dataset.destination]) selectDestination(destinationsById[button.dataset.destination]);
  });

  sendButton.addEventListener("click", function () {
    if (sending) return;
    sending = true;
    sendButton.disabled = true;
    status.textContent = "Previewing transfer route to " + selected.city + "…";
    routeProgress = 0;
    var started = performance.now();
    var animate = function (now) {
      routeProgress = Math.min(1, (now - started) / 850);
      drawGlobe();
      if (routeProgress < 1) { window.requestAnimationFrame(animate); return; }
      sending = false;
      sendButton.disabled = false;
      status.dataset.state = "success";
      status.textContent = "Route previewed: ₦20,000 to " + selected.city + ". Sign in to send a live transfer.";
    };
    window.requestAnimationFrame(animate);
  });

  infoButton.addEventListener("click", function () {
    var expanded = infoButton.getAttribute("aria-expanded") !== "true";
    info.hidden = !expanded;
    infoButton.setAttribute("aria-expanded", String(expanded));
  });

  if ("ResizeObserver" in window) new ResizeObserver(resizeCanvas).observe(globe);
  window.addEventListener("resize", resizeCanvas);
  selectDestination(selected);
  resizeCanvas();
})();

/* Currency Swap Preview */
(function () {
  var section = document.getElementById("swap-ch");
  if (!section) return;

  var slider = section.querySelector("#swapAmt");
  var amountLabel = section.querySelector("#swapAmtLbl");
  var output = section.querySelector("#swapOut");
  var rateLabel = section.querySelector("#swapRate");
  var chips = section.querySelector("#swapChips");
  var targetCoin = section.querySelector("#coinTo");
  var swapButton = section.querySelector("#swapBtn");
  var toast = document.getElementById("toast");
  if (!slider || !amountLabel || !output || !rateLabel || !chips || !targetCoin || !swapButton) return;

  var cfgRates = (window.MERIDIAN_CONFIG && window.MERIDIAN_CONFIG.rates) || {};
  var currencies = {
    USD: { name: "dollars", symbol: "$", flag: "fl-us", rate: Number(cfgRates.USD) > 0 ? Number(cfgRates.USD) : 1500 },
    GBP: { name: "pounds", symbol: "£", flag: "fl-gb", rate: Number(cfgRates.GBP) > 0 ? Number(cfgRates.GBP) : 1900 },
    EUR: { name: "euros", symbol: "€", flag: "fl-eu", rate: Number(cfgRates.EUR) > 0 ? Number(cfgRates.EUR) : 1650 }
  };
  var currency = "USD";
  var toastTimer;

  function formatNaira(value) { return "₦" + Number(value).toLocaleString("en-NG"); }
  function formatOutput(value) {
    return new Intl.NumberFormat("en-US", { style: "currency", currency: currency, minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(value);
  }

  function update() {
    var target = currencies[currency];
    var amount = Number(slider.value);
    var converted = amount / target.rate;
    var progress = ((amount - Number(slider.min)) / (Number(slider.max) - Number(slider.min))) * 100;
    amountLabel.textContent = formatNaira(amount);
    output.textContent = formatOutput(converted);
    rateLabel.textContent = (cfgRates[currency] ? "Live rate: " : "Indicative rate: ") + formatNaira(target.rate) + " = " + target.symbol + "1.00";
    slider.style.setProperty("--v", progress + "%");
    swapButton.textContent = "Preview " + formatNaira(amount) + " → " + target.name;
    targetCoin.querySelector("[data-sym]").textContent = target.symbol;
    targetCoin.querySelector("small").innerHTML = '<svg class="flag" aria-hidden="true" focusable="false"><use href="#' + target.flag + '"></use></svg> ' + currency;
    chips.querySelectorAll("button[data-cur]").forEach(function (chip) {
      chip.setAttribute("aria-pressed", String(chip.dataset.cur === currency));
    });
  }

  slider.addEventListener("input", update);
  chips.addEventListener("click", function (event) {
    var chip = event.target.closest("button[data-cur]");
    if (chip && currencies[chip.dataset.cur]) { currency = chip.dataset.cur; update(); }
  });
  swapButton.addEventListener("click", function () {
    var target = currencies[currency];
    var amount = Number(slider.value);
    if (toast) {
      window.clearTimeout(toastTimer);
      toast.textContent = "Quote preview: " + formatNaira(amount) + " ≈ " + formatOutput(amount / target.rate) + ". Sign in to convert.";
      toast.classList.add("on");
      toastTimer = window.setTimeout(function () { toast.classList.remove("on"); }, 2800);
    }
  });
  update();
})();

/* Interactive Airtime Top-up Preview */
(function () {
  var form = document.getElementById("topupForm");
  if (!form) return;
  var networkGroup = form.querySelector("#netChips");
  var amountGroup = form.querySelector("#amtChips");
  var phoneInput = form.querySelector("#phone");
  var result = form.querySelector("#topupRes");
  var message = form.querySelector("#topupMsg");
  var submitButton = form.querySelector('[type="submit"]');
  var walletAmount = document.getElementById("dockAmt");
  if (!networkGroup || !amountGroup || !phoneInput || !result || !message || !submitButton || !walletAmount) return;

  var initialBalance = 250000;
  var balance = initialBalance;
  var amount = 1000;
  var formatNaira = function (v) { return "₦" + Number(v).toLocaleString("en-NG"); };

  function updateButton() { submitButton.textContent = "Preview top up " + formatNaira(amount); }

  networkGroup.addEventListener("click", function (event) {
    var btn = event.target.closest("button");
    if (!btn) return;
    networkGroup.querySelectorAll(".chip").forEach(function (c) { c.setAttribute("aria-pressed", String(c === btn)); });
  });
  amountGroup.addEventListener("click", function (event) {
    var btn = event.target.closest("button[data-v]");
    if (!btn) return;
    amount = Number(btn.dataset.v);
    amountGroup.querySelectorAll(".chip").forEach(function (c) { c.setAttribute("aria-pressed", String(c === btn)); });
    updateButton();
  });
  form.addEventListener("submit", function (event) {
    event.preventDefault();
    var phone = phoneInput.value.trim();
    var digits = phone.replace(/\D/g, "");
    var valid = /^0[789]\d{9}$/.test(digits) || (/^\+234/.test(phone) && digits.length === 13 && /^234[789]\d{9}$/.test(digits));
    if (!valid) {
      message.textContent = "Enter a valid Nigerian mobile number (11 digits or +234).";
      result.classList.remove("done");
      return;
    }
    balance = Math.max(0, balance - amount);
    walletAmount.textContent = formatNaira(balance);
    var netBtn = networkGroup.querySelector('[aria-pressed="true"]');
    var net = netBtn ? netBtn.textContent.trim() : "MTN";
    message.textContent = "Preview: " + formatNaira(amount) + " " + net + " airtime to " + phone + ". Sign in to complete live orders.";
    result.classList.add("done");
  });
  var resetButton = document.getElementById("resetBtn");
  if (resetButton) {
    resetButton.addEventListener("click", function () {
      balance = initialBalance;
      walletAmount.textContent = formatNaira(balance);
      result.classList.remove("done");
      message.textContent = "Demo balance reset. Pick a network and amount, then preview.";
    });
  }
  updateButton();
})();

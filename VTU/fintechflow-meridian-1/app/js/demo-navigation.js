(function () {
  const personalTargets = {
    "/swap": "swap-ch",
    "/send": "send-ch",
    "/cards": "card-ch",
    "/refill": "bills-ch",
    "/giftcards": "bills-ch",
    "/esim": "bills-ch",
    "/vault": "vault-ch",
    "/accounts": "swap-ch",
    "/novatag": "send-ch",
    "/tuition": "bills-ch",
    "/otc": "swap-ch",
    "/lifestyle": "tiles-ch",
    "/about": "trust",
    "/get": "download",
  };

  const businessTargets = {
    "/business/payments": "biz-feature-0",
    "/business/wallets": "biz-feature-1",
    "/business/payouts": "biz-feature-2",
    "/business/cards": "biz-feature-3",
    "/business/teams": "biz-feature-4",
    "/business/invoices": "biz-feature-5",
    "/business/epos": "biz-feature-3",
    "/business/developers": "biz-feature-6",
    "/business/partners": "biz-feature-6",
    "/business/resources": "biz-resources",
    "/business/solutions": "biz-solutions",
    "/business/start": "biz-start",
  };

  function navigate(file, hash) {
    const menu = document.getElementById("sheet");
    const menuButton = document.getElementById("menuBtn");
    if (menu) menu.hidden = true;
    if (menuButton) menuButton.setAttribute("aria-expanded", "false");

    const destination =
      window.location.protocol === "file:"
        ? file
        : file === "business.html"
          ? "/business"
          : "/";
    window.location.href = destination + (hash ? "#" + hash : "");
  }

  function handleWorld(button) {
    const world = button.getAttribute("data-w");
    if (world !== "personal" && world !== "business") return;
    document.documentElement.dataset.world = world;
    document.querySelectorAll('#pswitch [role="radio"][data-w]').forEach((choice) => {
      const selected = choice === button;
      choice.setAttribute("aria-checked", String(selected));
      choice.tabIndex = selected ? 0 : -1;
    });
    if (world === "business") {
      navigate("business.html");
    } else {
      navigate("index.html", "hero");
    }
  }

  function syncWorldSwitch() {
    const world = document.documentElement.dataset.world;
    document.querySelectorAll('#pswitch [role="radio"][data-w]').forEach((choice) => {
      const selected = choice.dataset.w === world;
      choice.setAttribute("aria-checked", String(selected));
      choice.tabIndex = selected ? 0 : -1;
    });
  }

  function addWorldLinks() {
    const world = document.documentElement.dataset.world;
    if (world !== "business" && world !== "personal") return;

    const destination =
      window.location.protocol === "file:"
        ? world === "business"
          ? "index.html"
          : "business.html"
        : world === "business"
          ? "/"
          : "/business";
    const label = world === "business" ? "Personal" : "Business";

    ["links", "mlinks"].forEach((id) => {
      const container = document.getElementById(id);
      if (!container) return;
      if ([...container.querySelectorAll("a")].some((link) => link.textContent.trim() === label)) {
        return;
      }

      const link = document.createElement("a");
      link.href = destination;
      link.textContent = label;
      link.dataset.demoWorldLink = "";
      container.appendChild(link);
    });
  }

  document.addEventListener("DOMContentLoaded", () => {
    addWorldLinks();
    syncWorldSwitch();
  });
  new MutationObserver(addWorldLinks).observe(document.documentElement, {
    attributes: true,
    attributeFilter: ["data-world"],
    childList: true,
    subtree: true,
  });

  document.addEventListener(
    "click",
    function (event) {
      const target = event.target;
      if (!(target instanceof Element)) return;

      const worldButton = target.closest('[role="radio"][data-w]');
      if (worldButton) {
        event.preventDefault();
        event.stopImmediatePropagation();
        handleWorld(worldButton);
        return;
      }

      const menuButton = target.closest("#menuBtn");
      if (menuButton) {
        event.preventDefault();
        event.stopImmediatePropagation();
        const menu = document.getElementById("sheet");
        if (menu) {
          menu.hidden = !menu.hidden;
          menuButton.setAttribute("aria-expanded", String(!menu.hidden));
        }
        return;
      }

      const link = target.closest("a[href]");
      if (!link) return;

      const href = link.getAttribute("href");
      if (!href || href.startsWith("//") || /^[a-z][a-z\d+.-]*:/i.test(href)) return;

      if (href.startsWith("#")) {
        if (href === "#home" && document.documentElement.dataset.world === "business") {
          event.preventDefault();
          event.stopImmediatePropagation();
          navigate("index.html", "hero");
        }
        else {
          const menu = document.getElementById("sheet");
          const menuButton = document.getElementById("menuBtn");
          if (menu) menu.hidden = true;
          if (menuButton) menuButton.setAttribute("aria-expanded", "false");
        }
        return;
      }

      let pathname;
      let url;
      try {
        url = new URL(href, window.location.href);
        pathname = url.pathname.replace(/\.html$/, "");
      } catch {
        return;
      }

      if (/\.html$/i.test(url.pathname)) {
        event.preventDefault();
        event.stopImmediatePropagation();
        const file = /business\.html$/i.test(url.pathname) ? "business.html" : "index.html";
        navigate(file, url.hash.slice(1));
        return;
      }

      event.preventDefault();
      event.stopImmediatePropagation();

      if (pathname === "/business" || pathname.startsWith("/business/")) {
        navigate("business.html", businessTargets[pathname] || "biz-wrapped");
        return;
      }

      if (pathname === "/" || pathname === "/index") {
        navigate("index.html", "hero");
        return;
      }

      navigate("index.html", personalTargets[pathname] || "tiles-ch");
    },
    true
  );
})();

(function () {
  const section = document.getElementById("duo-ch");
  if (!section) return;

  const phoneCornerStyle = document.createElement("style");
  phoneCornerStyle.textContent =
    '.duo-dev[data-open="false"] .duo-r .duo-shell{border-radius:6cqi}';
  document.head.appendChild(phoneCornerStyle);

  const device = section.querySelector("#duoDev");
  const leftScreen = section.querySelector("#duoL");
  const rightScreen = section.querySelector("#duoR");
  const cover = section.querySelector("#duoCover");
  const toggle = section.querySelector("#duoToggle");
  const steps = [...section.querySelectorAll(".duo-steps button[data-app]")];
  const leftHalf = section.querySelector(".duo-l");
  if (!device || !leftScreen || !rightScreen || !cover || !toggle || !leftHalf) return;

  const state = {
    app: "personal",
    open: false,
    paid: false,
    receipt: false,
    busy: false,
  };
  const formatMoney = (amount) => "₦" + amount.toLocaleString("en-NG");
  const brands = {
    personal: "FintechFlow",
    business: "FintechFlow Business",
  };
  const checkmark =
    '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12.5l4.5 4.5L19 7.5"/></svg>';

  function actionButton(action, label, alternate) {
    return (
      '<button type="button" class="dz-btn' +
      (alternate ? " alt" : "") +
      '" data-act="' +
      action +
      '"' +
      (state.busy ? " disabled" : "") +
      ">" +
      label +
      "</button>"
    );
  }

  function screenBar(app) {
    return '<div class="dz-bar"><span>9:41</span><b>' + brands[app] + "</b></div>";
  }

  function renderPersonal() {
    return [
      screenBar("personal") +
        '<div class="dz-body"><span class="dz-lbl">Hi Funmi</span><span class="dz-small">Balance</span><b class="dz-big">' +
        formatMoney(state.paid ? 95000 : 120000) +
        '</b><div class="dz-list"><span class="dz-small">Recent</span>' +
        (state.paid
          ? '<div class="dz-row dz-new"><i>AT</i><span>Ada’s Tailoring</span><b>−' +
            formatMoney(25000) +
            "</b></div>"
          : "") +
        '<div class="dz-row"><i>MT</i><span>Data top-up</span><b>−' +
        formatMoney(2000) +
        "</b></div></div></div>",
      screenBar("personal") +
        '<div class="dz-body dz-c"><i class="dz-av">AT</i><b class="dz-name">Ada’s Tailoring</b><span class="dz-small">Invoice #0142</span><b class="dz-big">' +
        formatMoney(25000) +
        "</b>" +
        (state.paid
          ? '<div class="dz-done">' +
            checkmark +
            "<span>Paid</span></div>" +
            actionButton("go-business", "See it in Business →", true)
          : actionButton("pay", state.busy ? "Paying…" : "Pay " + formatMoney(25000))) +
        "</div>",
    ];
  }

  function renderBusiness() {
    return [
      screenBar("business") +
        '<div class="dz-body"><span class="dz-lbl">Ada’s Tailoring</span><span class="dz-small">Business balance</span><b class="dz-big">' +
        formatMoney(state.paid ? 505000 : 480000) +
        '</b><div class="dz-list"><span class="dz-small">Incoming</span>' +
        (state.paid
          ? '<div class="dz-row dz-new"><i>FA</i><span>Funmi A.</span><b class="dz-in">+' +
            formatMoney(25000) +
            "</b></div>"
          : '<div class="dz-row dz-wait"><span>Waiting for payment</span></div>') +
        "</div></div>",
      screenBar("business") +
        '<div class="dz-body dz-c"><span class="dz-small">Invoice #0142</span><b class="dz-name">Funmi A.</b><b class="dz-big">' +
        formatMoney(25000) +
        '</b><span class="dz-pill ' +
        (state.paid ? "ok" : "") +
        '">' +
        (state.paid ? "Paid" : "Unpaid") +
        "</span>" +
        (state.paid
          ? state.receipt
            ? '<div class="dz-done">' +
              checkmark +
              "<span>Receipt sent</span></div>" +
              actionButton("restart", "Start again", true)
            : actionButton("receipt", state.busy ? "Sending…" : "Send receipt")
          : actionButton("go-personal", "Pay it in FintechFlow →", true)) +
        "</div>",
    ];
  }

  function render() {
    const screens = {
      personal: renderPersonal,
      business: renderBusiness,
    }[state.app]();

    device.dataset.app = state.app;
    leftScreen.innerHTML = screens[0];
    rightScreen.innerHTML = screens[1];
    steps.forEach((step) => {
      if (step.dataset.app === state.app) {
        step.setAttribute("aria-current", "step");
      } else {
        step.removeAttribute("aria-current");
      }
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
    toggle.setAttribute("aria-label", open ? "Close the phone" : "Open the phone");
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

    const finish = (update) => {
      state.busy = true;
      render();
      window.setTimeout(() => {
        state.busy = false;
        update();
        render();
      }, 500);
    };

    if (action === "pay") {
      finish(() => {
        state.paid = true;
      });
    } else if (action === "receipt") {
      finish(() => {
        state.receipt = true;
      });
    } else if (action === "restart") {
      Object.assign(state, {
        app: "personal",
        paid: false,
        receipt: false,
      });
      render();
    } else if (action.startsWith("go-")) {
      selectApp(action.slice(3));
    }
  }

  section.addEventListener("click", (event) => {
    const step = event.target.closest(".duo-steps button[data-app]");
    if (step) {
      selectApp(step.dataset.app);
      return;
    }

    if (event.target.closest("#duoToggle")) {
      setOpen(!state.open);
      return;
    }

    if (event.target.closest("#duoCover")) {
      setOpen(true);
      return;
    }

    const action = event.target.closest("[data-act]");
    if (action && !action.disabled) runAction(action.dataset.act);
  });

  render();
  setOpen(false);
})();

(function () {
  const section = document.getElementById("send-ch");
  if (!section) return;

  const destinations = [
    { id: "london", city: "London", country: "United Kingdom", flag: "gb", point: [0.04, -0.38] },
    { id: "new-york", city: "New York", country: "United States", flag: "us", point: [-0.38, -0.08] },
    { id: "toronto", city: "Toronto", country: "Canada", flag: "ca", point: [-0.3, -0.3] },
    { id: "paris", city: "Paris", country: "France", flag: "fr", point: [0.14, -0.29] },
    { id: "johannesburg", city: "Johannesburg", country: "South Africa", flag: "za", point: [0.08, 0.42] },
  ];
  const origin = { city: "Lagos", point: [0.02, 0.28] };
  const chips = section.querySelector("#destChips");
  const globe = section.querySelector("#homeGlobe");
  const canvas = globe && globe.querySelector("canvas");
  const label = section.querySelector("#globeLabel");
  const sendButton = section.querySelector("#sendBtn");
  const status = section.querySelector("#sendStatus");
  const infoButton = section.querySelector("#transferInfoBtn");
  const info = section.querySelector("#transferInfo");
  if (!chips || !canvas || !label || !sendButton || !status || !infoButton || !info) return;

  const context = canvas.getContext("2d");
  if (!context) {
    status.textContent = "The destination globe is unavailable in this browser.";
    return;
  }

  const destinationsById = new Map(destinations.map((destination) => [destination.id, destination]));
  let selected = destinations[0];
  let routeProgress = 1;
  let sending = false;

  const transferStyle = document.createElement("style");
  transferStyle.textContent =
    '#sendStatus:empty{display:none}#sendStatus{min-height:1.5em;margin:12px 0 0;color:var(--text2)}#sendStatus[data-state="success"]{color:#26754b;font-weight:600}#homeGlobe canvas{display:block;border-radius:50%}#destChips .chip svg.flag{width:20px;height:20px;vertical-align:-5px;margin-right:6px}#transferInfo{max-width:38em;margin:12px 0 0;padding:14px 16px;border:1px solid var(--rim);border-radius:var(--r-sm);background:var(--chip);color:var(--text2)}';
  document.head.appendChild(transferStyle);

  chips.innerHTML = destinations
    .map(
      (destination) =>
        '<button class="chip" type="button" data-destination="' +
        destination.id +
        '" aria-pressed="' +
        (destination.id === selected.id) +
        '" aria-label="' +
        destination.city +
        ', ' +
        destination.country +
        '">' +
        '<svg class="flag" aria-hidden="true" focusable="false"><use href="#fl-' +
        destination.flag +
        '"></use></svg>' +
        destination.city +
        "</button>"
    )
    .join("");

  function setLabel(destination) {
    label.innerHTML =
      '<svg class="flag" aria-hidden="true" focusable="false"><use href="#fl-ng"></use></svg> ' +
      origin.city +
      ' to <svg class="flag" aria-hidden="true" focusable="false"><use href="#fl-' +
      destination.flag +
      '"></use></svg> ' +
      destination.city;
    canvas.setAttribute(
      "aria-label",
      "Globe showing a demo transfer route from Lagos to " + destination.city
    );
    sendButton.textContent = "Send ₦20,000 to " + destination.city;
  }

  function resizeCanvas() {
    const bounds = canvas.getBoundingClientRect();
    if (!bounds.width || !bounds.height) return;
    const ratio = Math.min(window.devicePixelRatio || 1, 2);
    canvas.width = Math.round(bounds.width * ratio);
    canvas.height = Math.round(bounds.height * ratio);
    context.setTransform(ratio, 0, 0, ratio, 0, 0);
    drawGlobe();
  }

  function drawGlobe() {
    const width = canvas.clientWidth;
    const height = canvas.clientHeight;
    if (!width || !height) return;

    const radius = Math.min(width, height) * 0.46;
    const centerX = width / 2;
    const centerY = height / 2;
    context.clearRect(0, 0, width, height);

    const sphere = context.createRadialGradient(
      centerX - radius * 0.34,
      centerY - radius * 0.42,
      radius * 0.08,
      centerX,
      centerY,
      radius * 1.12
    );
    sphere.addColorStop(0, "#34245f");
    sphere.addColorStop(0.7, "#17112c");
    sphere.addColorStop(1, "#090817");
    context.beginPath();
    context.arc(centerX, centerY, radius, 0, Math.PI * 2);
    context.fillStyle = sphere;
    context.fill();
    context.save();
    context.beginPath();
    context.arc(centerX, centerY, radius - 1, 0, Math.PI * 2);
    context.clip();

    for (let latitude = -80; latitude <= 80; latitude += 8) {
      const lat = (latitude * Math.PI) / 180;
      for (let longitude = -88; longitude <= 88; longitude += 8) {
        const lon = (longitude * Math.PI) / 180;
        const depth = Math.cos(lat) * Math.cos(lon);
        const x = centerX + radius * Math.cos(lat) * Math.sin(lon);
        const y = centerY - radius * Math.sin(lat);
        const size = 0.65 + Math.max(0, depth) * 0.72;
        context.beginPath();
        context.arc(x, y, size, 0, Math.PI * 2);
        context.fillStyle = "rgba(228,220,255," + (0.25 + Math.max(0, depth) * 0.48) + ")";
        context.fill();
      }
    }

    const point = (coordinates) => ({
      x: centerX + coordinates[0] * radius,
      y: centerY + coordinates[1] * radius,
    });
    const start = point(origin.point);
    const end = point(selected.point);
    const control = {
      x: (start.x + end.x) / 2 - radius * 0.2,
      y: (start.y + end.y) / 2 - radius * 0.18,
    };

    context.beginPath();
    context.moveTo(start.x, start.y);
    context.quadraticCurveTo(control.x, control.y, end.x, end.y);
    context.strokeStyle = "rgba(155,126,255,.32)";
    context.lineWidth = 3;
    context.stroke();

    context.beginPath();
    context.moveTo(start.x, start.y);
    const progress = Math.max(0, Math.min(1, routeProgress));
    const routeEnd = {
      x: (1 - progress) ** 2 * start.x + 2 * (1 - progress) * progress * control.x + progress ** 2 * end.x,
      y: (1 - progress) ** 2 * start.y + 2 * (1 - progress) * progress * control.y + progress ** 2 * end.y,
    };
    const routeControl = {
      x: start.x + (control.x - start.x) * progress,
      y: start.y + (control.y - start.y) * progress,
    };
    context.quadraticCurveTo(routeControl.x, routeControl.y, routeEnd.x, routeEnd.y);
    context.strokeStyle = "#7957ff";
    context.lineWidth = 2;
    context.shadowColor = "#8b69ff";
    context.shadowBlur = 12;
    context.stroke();
    context.shadowBlur = 0;

    const drawMarker = (location, title, active) => {
      context.beginPath();
      context.arc(location.x, location.y, active ? 9 : 6, 0, Math.PI * 2);
      context.fillStyle = "#fff";
      context.fill();
      context.beginPath();
      context.arc(location.x, location.y, active ? 4 : 2.5, 0, Math.PI * 2);
      context.fillStyle = "#7049f7";
      context.fill();
      context.font = "600 14px system-ui, sans-serif";
      context.fillStyle = "#fff";
      context.textBaseline = "middle";
      context.fillText(title, location.x + 12, location.y);
    };
    drawMarker(start, origin.city, false);
    drawMarker(end, selected.city, true);
    context.restore();

    context.beginPath();
    context.arc(centerX, centerY, radius, 0, Math.PI * 2);
    context.strokeStyle = "rgba(232,224,255,.3)";
    context.lineWidth = 1;
    context.stroke();
  }

  function selectDestination(destination) {
    selected = destination;
    routeProgress = 1;
    status.textContent = "";
    delete status.dataset.state;
    chips.querySelectorAll("button[data-destination]").forEach((button) => {
      button.setAttribute("aria-pressed", String(button.dataset.destination === destination.id));
    });
    setLabel(destination);
    drawGlobe();
  }

  chips.addEventListener("click", (event) => {
    const button = event.target.closest("button[data-destination]");
    if (!button || sending) return;
    const destination = destinationsById.get(button.dataset.destination);
    if (destination) selectDestination(destination);
  });

  sendButton.addEventListener("click", () => {
    if (sending) return;
    sending = true;
    sendButton.disabled = true;
    status.textContent = "Sending demo transfer to " + selected.city + "…";
    routeProgress = 0;
    const started = performance.now();
    const animate = (now) => {
      routeProgress = Math.min(1, (now - started) / 900);
      drawGlobe();
      if (routeProgress < 1) {
        window.requestAnimationFrame(animate);
        return;
      }
      sending = false;
      sendButton.disabled = false;
      status.dataset.state = "success";
      status.textContent =
        "Demo transfer of ₦20,000 to " + selected.city + " complete. No money was moved.";
    };
    window.requestAnimationFrame(animate);
  });

  infoButton.addEventListener("click", () => {
    const expanded = infoButton.getAttribute("aria-expanded") !== "true";
    info.hidden = !expanded;
    infoButton.setAttribute("aria-expanded", String(expanded));
  });

  const observer = new ResizeObserver(resizeCanvas);
  observer.observe(globe);
  window.addEventListener("resize", resizeCanvas);
  selectDestination(selected);
  resizeCanvas();
})();

(function () {
  const section = document.getElementById("swap-ch");
  if (!section) return;

  const slider = section.querySelector("#swapAmt");
  const amountLabel = section.querySelector("#swapAmtLbl");
  const output = section.querySelector("#swapOut");
  const rateLabel = section.querySelector("#swapRate");
  const chips = section.querySelector("#swapChips");
  const targetCoin = section.querySelector("#coinTo");
  const swapButton = section.querySelector("#swapBtn");
  const toast = document.getElementById("toast");
  if (!slider || !amountLabel || !output || !rateLabel || !chips || !targetCoin || !swapButton) {
    return;
  }

  const currencies = {
    USD: { name: "dollars", label: "Dollars", symbol: "$", flag: "fl-us", rate: 1500 },
    GBP: { name: "pounds", label: "Pounds", symbol: "£", flag: "fl-gb", rate: 1900 },
    EUR: { name: "euros", label: "Euros", symbol: "€", flag: "fl-eu", rate: 1650 },
  };
  let currency = "USD";
  let toastTimer;
  let buttonTimer;

  function formatNaira(value) {
    return "₦" + Number(value).toLocaleString("en-NG");
  }

  function formatOutput(value) {
    return new Intl.NumberFormat("en-US", {
      style: "currency",
      currency,
      minimumFractionDigits: 2,
      maximumFractionDigits: 2,
    }).format(value);
  }

  function update() {
    const target = currencies[currency];
    const amount = Number(slider.value);
    const converted = amount / target.rate;
    const progress =
      ((amount - Number(slider.min)) / (Number(slider.max) - Number(slider.min))) * 100;

    amountLabel.textContent = formatNaira(amount);
    output.textContent = formatOutput(converted);
    output.setAttribute("aria-live", "polite");
    rateLabel.textContent =
      "Demo rate: " + formatNaira(target.rate) + " = " + target.symbol + "1.00";
    slider.style.setProperty("--v", progress + "%");
    swapButton.textContent = "Swap " + formatNaira(amount) + " to " + target.name;
    swapButton.disabled = false;
    targetCoin.querySelector("[data-sym]").textContent = target.symbol;
    targetCoin.querySelector("small").innerHTML =
      '<svg class="flag" aria-hidden="true" focusable="false"><use href="#' +
      target.flag +
      '"></use></svg> ' +
      currency;

    chips.querySelectorAll("button[data-cur]").forEach((chip) => {
      chip.setAttribute("aria-pressed", String(chip.dataset.cur === currency));
    });
  }

  function showToast(message) {
    if (!toast) return;
    window.clearTimeout(toastTimer);
    toast.textContent = message;
    toast.classList.add("on");
    toastTimer = window.setTimeout(() => {
      toast.classList.remove("on");
    }, 2600);
  }

  slider.addEventListener("input", update);
  chips.addEventListener("click", (event) => {
    const chip = event.target.closest("button[data-cur]");
    if (!chip || !currencies[chip.dataset.cur]) return;
    currency = chip.dataset.cur;
    update();
  });
  swapButton.addEventListener("click", () => {
    if (swapButton.disabled) return;

    const target = currencies[currency];
    const amount = Number(slider.value);
    const result = formatOutput(amount / target.rate);
    swapButton.disabled = true;
    swapButton.textContent = "Swapping…";
    window.clearTimeout(buttonTimer);
    window.setTimeout(() => {
      showToast("Demo swap complete: " + formatNaira(amount) + " → " + result);
      swapButton.textContent = "Swap complete";
      buttonTimer = window.setTimeout(update, 2200);
    }, 450);
  });

  update();
})();

(function () {
  const form = document.getElementById("topupForm");
  if (!form) return;

  const networkGroup = form.querySelector("#netChips");
  const amountGroup = form.querySelector("#amtChips");
  const phoneInput = form.querySelector("#phone");
  const result = form.querySelector("#topupRes");
  const message = form.querySelector("#topupMsg");
  const submitButton = form.querySelector('[type="submit"]');
  const walletAmount = document.getElementById("dockAmt");
  const toast = document.getElementById("toast");
  if (
    !networkGroup ||
    !amountGroup ||
    !phoneInput ||
    !result ||
    !message ||
    !submitButton ||
    !walletAmount
  ) {
    return;
  }

  const initialBalance = 250000;
  let balance = initialBalance;
  let amount = 1000;
  let processing = false;
  let processingTimer;
  let feedbackTimer;

  function formatNaira(value) {
    return "₦" + Number(value).toLocaleString("en-NG");
  }

  function selectedNetwork() {
    return networkGroup.querySelector('[aria-pressed="true"]')?.textContent.trim() || "MTN";
  }

  function setStatus(text, success, error) {
    message.textContent = text;
    result.classList.toggle("done", Boolean(success));
    result.setAttribute("role", error ? "alert" : "status");
  }

  function showToast(text) {
    if (!toast) return;
    window.clearTimeout(feedbackTimer);
    toast.textContent = text;
    toast.classList.add("on");
    feedbackTimer = window.setTimeout(() => toast.classList.remove("on"), 3200);
  }

  function updateButton() {
    submitButton.textContent = processing
      ? "Top up in progress…"
      : "Top up " + formatNaira(amount);
    submitButton.disabled = processing;
  }

  networkGroup.addEventListener("click", (event) => {
    const button = event.target.closest("button");
    if (!button || processing) return;
    networkGroup.querySelectorAll(".chip").forEach((chip) => {
      chip.setAttribute("aria-pressed", String(chip === button));
    });
  });

  amountGroup.addEventListener("click", (event) => {
    const button = event.target.closest("button[data-v]");
    if (!button || processing) return;
    amount = Number(button.dataset.v);
    amountGroup.querySelectorAll(".chip").forEach((chip) => {
      chip.setAttribute("aria-pressed", String(chip === button));
    });
    updateButton();
    result.classList.remove("done");
    setStatus("Pick a network and amount, then top up.", false);
  });

  phoneInput.addEventListener("input", () => {
    phoneInput.removeAttribute("aria-invalid");
  });

  form.addEventListener("submit", (event) => {
    event.preventDefault();
    if (processing) return;

    const phone = phoneInput.value.trim();
    const digits = phone.replace(/\D/g, "");
    const validNigerianNumber =
      /^0[789]\d{9}$/.test(digits) ||
      (/^\+234/.test(phone) && digits.length === 13 && /^234[789]\d{9}$/.test(digits));

    if (!validNigerianNumber) {
      phoneInput.setAttribute("aria-invalid", "true");
      setStatus("Enter a valid Nigerian mobile number (11 digits or +234).", false, true);
      phoneInput.focus();
      return;
    }

    if (balance < amount) {
      setStatus("Not enough demo naira. Refill the demo wallet to try again.", false, true);
      showToast("Not enough demo naira. Refill the demo wallet to try again.");
      return;
    }

    processing = true;
    result.classList.remove("done");
    setStatus("Sending your top-up…", false);
    updateButton();
    processingTimer = window.setTimeout(() => {
      processing = false;
      balance -= amount;
      walletAmount.textContent = formatNaira(balance);
      const network = selectedNetwork();
      const number = phone.replace(/\s+/g, " ");
      const confirmation =
        formatNaira(amount) + " " + network + " airtime sent to " + number + ". Demo only.";
      setStatus(confirmation, true);
      updateButton();
      showToast("Top-up complete. " + formatNaira(balance) + " demo naira left.");
    }, 450);
  });

  const resetButton = document.getElementById("resetBtn");
  if (resetButton) {
    resetButton.addEventListener("click", () => {
      window.clearTimeout(processingTimer);
      processing = false;
      balance = initialBalance;
      walletAmount.textContent = formatNaira(balance);
      result.classList.remove("done");
      phoneInput.removeAttribute("aria-invalid");
      setStatus("Demo wallet refilled. Pick a network and amount, then top up.", false);
      updateButton();
    });
  }

  updateButton();
})();

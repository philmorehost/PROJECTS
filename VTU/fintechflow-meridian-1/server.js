const http = require("http");
const path = require("path");
const fs = require("fs");

const root = __dirname;
const port = Number(process.env.PORT || 4173);

const mimeTypes = {
  ".html": "text/html; charset=utf-8",
  ".js": "application/javascript; charset=utf-8",
  ".css": "text/css; charset=utf-8",
  ".json": "application/json; charset=utf-8",
  ".webp": "image/webp",
  ".jpg": "image/jpeg",
  ".jpeg": "image/jpeg",
  ".png": "image/png",
  ".ico": "image/x-icon",
  ".svg": "image/svg+xml",
  ".webmanifest": "application/manifest+json",
  ".mp4": "video/mp4",
};

function resolveFile(urlPath) {
  let pathname;
  try {
    pathname = decodeURIComponent(urlPath.split("?")[0]);
  } catch {
    return null;
  }

  if (pathname.startsWith("/_next/static/css/")) {
    return path.join(root, "css", path.basename(pathname));
  }
  if (pathname.startsWith("/_next/static/chunks/")) {
    return path.join(root, "js", path.basename(pathname));
  }
  if (pathname.startsWith("/glass/img/")) {
    return path.join(root, "images", path.basename(pathname));
  }
  if (pathname.startsWith("/glass/film/")) {
    return path.join(root, "images", path.basename(pathname));
  }

  if (pathname === "/" || pathname === "/index.html") {
    return path.join(root, "app", "index.html");
  }
  if (
    pathname === "/business" ||
    pathname.startsWith("/business/")
  ) {
    return path.join(root, "app", "business.html");
  }
  const candidate = path.join(root, pathname.replace(/^[/\\]+/, ""));
  if (fs.existsSync(candidate) && fs.statSync(candidate).isFile()) {
    return candidate;
  }

  const htmlCandidate = path.join(root, `${pathname.replace(/^[/\\]+/, "")}.html`);
  if (fs.existsSync(htmlCandidate) && fs.statSync(htmlCandidate).isFile()) {
    return htmlCandidate;
  }

  return path.join(root, "app", "index.html");
}

http.createServer((request, response) => {
  const file = resolveFile(request.url || "/");
  if (!file) {
    response.writeHead(404, { "Content-Type": "text/plain; charset=utf-8" });
    response.end("Not found");
    return;
  }
  fs.readFile(file, (error, data) => {
    if (error) {
      response.writeHead(404, { "Content-Type": "text/plain; charset=utf-8" });
      response.end("Not found");
      return;
    }

    response.writeHead(200, {
      "Content-Type": mimeTypes[path.extname(file).toLowerCase()] || "application/octet-stream",
      "Cache-Control": "no-cache",
    });
    response.end(data);
  });
}).listen(port, "127.0.0.1", () => {
  console.log(`FintechFlow demo: http://127.0.0.1:${port}`);
});

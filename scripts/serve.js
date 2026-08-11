/**
 * Локальный просмотр сайта: node scripts/serve.js
 * Открыть http://localhost:3000  (порт можно задать: node scripts/serve.js 8080)
 *
 * Нужен только для проверки на своём компьютере. Готовому сайту сервер не нужен -
 * это обычные HTML-файлы, их достаточно положить на любой хостинг.
 */
const http = require('http');
const fs = require('fs');
const path = require('path');

const ROOT = path.join(__dirname, '..');
const PORT = Number(process.argv[2]) || 3000;

const TYPES = {
    '.html': 'text/html; charset=utf-8',
    '.css': 'text/css; charset=utf-8',
    '.js': 'text/javascript; charset=utf-8',
    '.json': 'application/json; charset=utf-8',
    '.svg': 'image/svg+xml',
    '.png': 'image/png',
    '.jpg': 'image/jpeg',
    '.jpeg': 'image/jpeg',
    '.ico': 'image/x-icon'
};

http.createServer((req, res) => {
    const urlPath = decodeURIComponent(req.url.split('?')[0]);
    let file = path.join(ROOT, urlPath === '/' ? 'index.html' : urlPath);

    // не выпускаем за пределы папки проекта
    if (!file.startsWith(ROOT)) {
        res.writeHead(403).end('Forbidden');
        return;
    }

    if (fs.existsSync(file) && fs.statSync(file).isDirectory()) {
        file = path.join(file, 'index.html');
    }

    if (!fs.existsSync(file)) {
        res.writeHead(404, { 'Content-Type': 'text/plain; charset=utf-8' }).end('Страница не найдена: ' + urlPath);
        return;
    }

    res.writeHead(200, {
        'Content-Type': TYPES[path.extname(file).toLowerCase()] || 'application/octet-stream',
        'Cache-Control': 'no-store'
    });
    fs.createReadStream(file).pipe(res);
}).listen(PORT, () => {
    console.log(`Сайт открыт: http://localhost:${PORT}`);
    console.log('Остановить: Ctrl+C');
});

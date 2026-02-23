<?php
$isDev = getenv('APP_ENV') === 'development';
ini_set('display_errors', $isDev ? '1' : '0');
error_reporting(E_ALL);

/* ================= CONFIG ================= */

$mediaDir = __DIR__ . '/media/';
$perPage  = 20;
$allowed  = ['jpg','jpeg','png','gif','webp'];

if (!is_dir($mediaDir) || !is_readable($mediaDir)) {
    http_response_code(500);
    exit('Media directory is missing or not readable.');
}

/* ================= LOAD FILES ================= */

$files = [];
foreach (scandir($mediaDir) as $f) {
    if ($f === '.' || $f === '..') continue;
    $ext = strtolower(pathinfo($f, PATHINFO_EXTENSION));
    if (in_array($ext, $allowed, true)) {
        $files[] = $f;
    }
}

natcasesort($files);
$files = array_values($files);

/* ================= PAGINATION (ORIGINAL) ================= */

$totalItems = count($files);
$totalPages = max(1, ceil($totalItems / $perPage));

$currentPage = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$currentPage = max(1, min($currentPage, $totalPages));

$offset = ($currentPage - 1) * $perPage;
$pageFiles = array_slice($files, $offset, $perPage);

/* ================= METADATA BUILDER ================= */

function buildMeta($filePath){
    $meta = [
        'filename' => basename($filePath),
        'filesize' => is_file($filePath) ? round(filesize($filePath)/1024,1).' KB' : '-',
        'modified' => is_file($filePath) ? date('Y-m-d H:i', filemtime($filePath)) : '-'
    ];

    if(function_exists('exif_read_data')){
        try{
            $exif = @exif_read_data($filePath);
            if($exif){
                $meta['camera'] = ($exif['Make'] ?? '') . ' ' . ($exif['Model'] ?? '');
                $meta['taken']  = $exif['DateTimeOriginal'] ?? '';
            }
        } catch(Exception $e){}
    }

    return $meta;
}

$pageMeta = [];
foreach ($pageFiles as $f) {
    $pageMeta[] = buildMeta($mediaDir . $f);
}

/* ================= DOWNLOAD ================= */

if (isset($_GET['download']) && $_GET['download'] === 'all') {

    $zip = new ZipArchive();
    $zipName = 'gallery-images.zip';

    $tmpFile = tempnam(sys_get_temp_dir(), 'gallery_zip_');

    if ($zip->open($tmpFile, ZipArchive::CREATE) !== true) {
        http_response_code(500);
        exit('Unable to create ZIP file.');
    }

    foreach ($files as $file) {
        $filePath = $mediaDir . $file;
        if (is_file($filePath)) {
            $zip->addFile($filePath, $file);
        }
    }

    $zip->close();

    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="' . $zipName . '"');
    header('Content-Length: ' . filesize($tmpFile));

    readfile($tmpFile);
    unlink($tmpFile);
    exit;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Kent Family Vacation Gallery</title>

<style>
body {
    font-family: system-ui, sans-serif;
    padding: 1rem;
    margin: 0;
    background-image: url('assets/ghana-background.png');
    background-size: cover;
    background-position: center;
    background-attachment: fixed;
}

.gallery {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(180px,1fr));
    gap: 1rem;
}

.gallery img {
    width: 100%;
    cursor: pointer;
    border-radius: 6px;
    box-shadow: 0 2px 6px rgba(0,0,0,.15);
}

.gallery-item { text-align: center; }

.gallery-item figcaption {
    font-size: .85rem;
    color: #555;
    margin-top: .4rem;
}

.gallery-actions {
    text-align: center;
    margin: 1rem 0 1.5rem;
}

.pagination {
    display: flex;
    justify-content: center;
    flex-wrap: wrap;
    gap: .35rem;
    margin: 2rem auto;
}

.pagination a,
.pagination span {
    padding: .45rem .7rem;
    font-size: 14px;
    border-radius: 6px;
    text-decoration: none;
}

.pagination a {
    background: #fff;
    color: #333;
}

.pagination .current {
    background: #333;
    color: #fff;
}

.pagination .disabled {
    opacity: .4;
    pointer-events: none;
}

#lightbox {
    position: fixed;
    inset: 0;
    background: rgba(0,0,0,.92);
    display: none;
    align-items: center;
    justify-content: center;
    z-index: 9999;
    flex-direction: column;
}

#lightbox img {
    max-width: 95vw;
    max-height: 70vh;
    object-fit: contain;
}

.lb-btn {
    position: absolute;
    top: 50%;
    transform: translateY(-50%);
    font-size: 2rem;
    color: #fff;
    cursor: pointer;
    padding: .5rem;
}

.lb-prev { left: 1rem; }
.lb-next { right: 1rem; }

.lb-close {
    position: absolute;
    top: 1rem;
    right: 1rem;
    font-size: 2.5rem;
    cursor: pointer;
    color: #fff;
    width: 50px;
    height: 50px;
    display: flex;
    align-items: center;
    justify-content: center;
    user-select: none;
    transition: all 0.2s ease;
}

.lb-close:hover {
    color: #ccc;
    transform: scale(1.1);
}

.lb-controls-bottom {
    margin-top: 1rem;
    display: flex;
    gap: .5rem;
}

.lb-control-btn {
    background: rgba(255,255,255,.2);
    color: white;
    border: none;
    padding: .5rem 1rem;
    border-radius: 999px;
    cursor: pointer;
}

/* METADATA PANEL */
.lb-meta {
    margin-top: .8rem;
    font-size: .85rem;
    color: #ddd;
    text-align: center;
    line-height: 1.5;
}

.lb-copyright {
    margin-top: .8rem;
    font-size: .75rem;
    color: #999;
    text-align: center;
}

.page-title {
    font-size: clamp(1.6rem, 3vw, 2.2rem);
    font-weight: 700;
    margin: 0 auto 1.5rem;
    text-align: center;
    color: #222;
}

.page-title .subtitle {
    display: block;
    font-size: .9rem;
    font-weight: 400;
    color: #666;
    margin-top: .35rem;
}

.page-wrapper {
    background: rgba(255,255,255,.88);
    border-radius: 14px;
    padding: 1.5rem;
    max-width: 1400px;
    margin: 0 auto;
}

.page-footer {
    margin-top: 2.5rem;
    padding-top: 1.5rem;
    border-top: 1px solid rgba(0,0,0,.1);
    text-align: center;
}

.download-all {
    display: inline-block;
    margin-bottom: .9rem;
    padding: .55rem 1.1rem;
    font-size: .9rem;
    color: #fff;
    background: #333;
    border-radius: 8px;
    text-decoration: none;
}

.copyright {
    font-size: .75rem;
    color: #666;
}

.fullscreen-presentation img {
    max-width: 100vw !important;
    max-height: 100vh !important;
}
</style>
</head>

<body>
<div class="page-wrapper">
<h2 class="page-title">
Kent Family Ghana Vacation Gallery<br />
<span class="subtitle">
<?= $totalItems ?> images • Page <?= $currentPage ?> of <?= $totalPages ?>
</span>
</h2>

<section class="gallery">
<?php foreach ($pageFiles as $i => $file): ?>
<figure class="gallery-item">
<img src="media/<?= htmlspecialchars($file) ?>" loading="lazy">
<figcaption><?= htmlspecialchars(pathinfo($file, PATHINFO_FILENAME)) ?></figcaption>
</figure>
<?php endforeach; ?>
</section>

<!-- ORIGINAL PAGINATION -->
<div class="pagination">
<?php
$range = 2;

if ($currentPage > 1) echo '<a href="?page='.($currentPage-1).'">« Prev</a>';
else echo '<span class="disabled">« Prev</span>';

if ($currentPage > ($range + 1)) echo '<a href="?page=1">1</a><span>…</span>';

for ($p = max(1, $currentPage - $range); $p <= min($totalPages, $currentPage + $range); $p++) {
    if ($p === $currentPage) echo '<span class="current">'.$p.'</span>';
    else echo '<a href="?page='.$p.'">'.$p.'</a>';
}

if ($currentPage < ($totalPages - $range)) echo '<span>…</span><a href="?page='.$totalPages.'">'.$totalPages.'</a>';

if ($currentPage < $totalPages) echo '<a href="?page='.($currentPage+1).'">Next »</a>';
else echo '<span class="disabled">Next »</span>';
?>
</div>

<footer class="page-footer">
<a href="?download=all" class="download-all">⬇ Download All (<?= count($files) ?> images)</a>
<div class="copyright">© <?= date('Y') ?> — Absolon Kent. All Rights Reserved.</div>
</footer>
</div>

<!-- LIGHTBOX -->
<div id="lightbox">
<span class="lb-close">✕</span>
<span class="lb-btn lb-prev">‹</span>
<img id="lb-img">
<span class="lb-btn lb-next">›</span>

<div class="lb-meta" id="lb-meta"></div>

<div class="lb-controls-bottom">
<button class="lb-control-btn" id="playBtn">▶ Slideshow</button>
<button class="lb-control-btn" id="pauseBtn" style="display:none">⏸ Pause</button>
<button class="lb-control-btn" id="presentBtn">🖥 Presentation</button>
<button class="lb-control-btn" id="downloadBtn">⬇ Download</button>
</div>

<div class="lb-copyright">© <?= date('Y') ?> — Absolon Kent. All Rights Reserved.</div>
</div>

<script>
const images = [...document.querySelectorAll('.gallery img')];
const pageMeta = <?= json_encode($pageMeta, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;

const lightbox = document.getElementById('lightbox');
const lbImg = document.getElementById('lb-img');
const lbMeta = document.getElementById('lb-meta');
const playBtn = document.getElementById('playBtn');
const pauseBtn = document.getElementById('pauseBtn');
const presentBtn = document.getElementById('presentBtn');
const downloadBtn = document.getElementById('downloadBtn');

let currentIndex = 0;
let slideshowTimer = null;
let presentationMode = false;

function renderMeta(i){
 const m = pageMeta[i] || {};
 const lines = [
  m.filename || '',
  `Size: ${m.filesize || '-'}`,
  m.camera ? `Camera: ${m.camera}` : null,
  m.taken ? `Taken: ${m.taken}` : null,
 ].filter(Boolean);
 lbMeta.replaceChildren(...lines.map((line, idx) => {
  const node = document.createElement(idx === 0 ? 'strong' : 'span');
  node.textContent = line;
  return node;
 }).flatMap((node, idx, arr) => idx < arr.length - 1 ? [node, document.createElement('br')] : [node]));
}

function openLightbox(i){
 currentIndex=i;
 lbImg.src = images[i].src;
 renderMeta(i);
 lightbox.style.display='flex';
}
function closeLightbox(){ stopSlideshow(); lightbox.style.display='none'; }
function next(){ openLightbox((currentIndex+1)%images.length); }
function prev(){ openLightbox((currentIndex-1+images.length)%images.length); }

images.forEach((img,i)=>img.onclick=()=>openLightbox(i));

document.querySelector('.lb-close').onclick=closeLightbox;
document.querySelector('.lb-next').onclick=next;
document.querySelector('.lb-prev').onclick=prev;

function startSlideshow(){
 if(slideshowTimer) return;
 slideshowTimer=setInterval(next,6000);
 playBtn.style.display='none';
 pauseBtn.style.display='inline-block';
 lbMeta.style.display='none';
}
function stopSlideshow(){
 clearInterval(slideshowTimer);
 slideshowTimer=null;
 playBtn.style.display='inline-block';
 pauseBtn.style.display='none';
 lbMeta.style.display='block';
}

playBtn.onclick=startSlideshow;
pauseBtn.onclick=stopSlideshow;

presentBtn.onclick=()=>{
 presentationMode=!presentationMode;
 document.body.classList.toggle('fullscreen-presentation',presentationMode);
 lbMeta.style.display=presentationMode?'none':'block';
 if(presentationMode && lightbox.requestFullscreen) lightbox.requestFullscreen().catch(()=>{});
 if(!presentationMode && document.fullscreenElement) document.exitFullscreen().catch(()=>{});
};

downloadBtn.onclick=()=>{
 const filename = images[currentIndex].src.split('/').pop();
 const link = document.createElement('a');
 link.href = images[currentIndex].src;
 link.download = filename;
 link.click();
};

document.addEventListener('keydown',e=>{
 if(lightbox.style.display!=='flex') return;
 if(e.key==='Escape') closeLightbox();
 if(e.key==='ArrowRight') next();
 if(e.key==='ArrowLeft') prev();
 if(e.key===' ') { e.preventDefault(); slideshowTimer?stopSlideshow():startSlideshow(); }
});
</script>

</body>
</html>

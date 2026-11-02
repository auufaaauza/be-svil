<?php

namespace App\Learning;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;
use ZipArchive;

class SlideReader
{
    private ZipArchive $zip;

    private float $width = 12192000;

    private float $height = 6858000;

    private array $colors = ['dk1' => '000000', 'lt1' => 'FFFFFF', 'dk2' => '24473E', 'lt2' => 'F7F9F8', 'accent1' => '257968', 'accent2' => 'E8AD66', 'accent3' => '6FA6BF', 'accent4' => 'A58CB8', 'accent5' => '7CA873', 'accent6' => 'D78C8C', 'tx1' => '000000', 'bg1' => 'FFFFFF', 'tx2' => '24473E', 'bg2' => 'F7F9F8'];

    public function __construct(string $path)
    {
        abort_unless(class_exists(DOMDocument::class), 422, 'Pembaca PPTX memerlukan ekstensi DOM PHP.');
        abort_unless(class_exists(ZipArchive::class), 422, 'Pembaca PPTX memerlukan ekstensi ZIP PHP.');
        $this->zip = new ZipArchive;
        abort_unless($this->zip->open($path) === true, 422, 'Berkas PowerPoint tidak dapat dibaca.');
        abort_if($this->zip->numFiles > 2500, 422, 'Presentasi terlalu besar untuk pratinjau.');
        $total = 0;
        for ($i = 0; $i < $this->zip->numFiles; $i++) {
            $stat = $this->zip->statIndex($i);
            $total += $stat['size'];
            abort_if($stat['size'] > 12 * 1024 * 1024 || $total > 80 * 1024 * 1024, 422, 'Ukuran isi presentasi melampaui batas pratinjau.');
        }
        abort_unless($this->zip->locateName('ppt/presentation.xml') !== false && $this->zip->locateName('[Content_Types].xml') !== false, 422, 'Gunakan berkas PowerPoint .pptx yang valid.');
        abort_if($this->zip->locateName('ppt/vbaProject.bin') !== false, 422, 'Unggah PPTX tanpa makro atau gunakan PDF.');
    }

    public function __destruct()
    {
        if (isset($this->zip)) {
            $this->zip->close();
        }
    }

    private function xml(string $path): ?DOMDocument
    {
        $bytes = $this->zip->getFromName($path);
        if ($bytes === false) {
            return null;
        }
        abort_if(strlen($bytes) > 3 * 1024 * 1024 || preg_match('/<!DOCTYPE|<!ENTITY/i', $bytes), 422, 'Bagian XML presentasi tidak didukung.');
        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $ok = $document->loadXML($bytes, LIBXML_NONET | LIBXML_NOBLANKS);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        abort_unless($ok, 422, 'Isi presentasi tidak dapat dibaca.');

        return $document;
    }

    private function xpath(DOMDocument $document): DOMXPath
    {
        $x = new DOMXPath($document);
        $x->registerNamespace('p', 'http://schemas.openxmlformats.org/presentationml/2006/main');
        $x->registerNamespace('a', 'http://schemas.openxmlformats.org/drawingml/2006/main');
        $x->registerNamespace('r', 'http://schemas.openxmlformats.org/officeDocument/2006/relationships');

        return $x;
    }

    private function resolved(string $base, string $target): ?string
    {
        if (preg_match('~^[a-z]+:|\\\\|\x00~i', $target)) {
            return null;
        }$path = str_starts_with($target, '/') ? ltrim($target, '/') : dirname($base).'/'.$target;
        $parts = [];
        foreach (explode('/', $path) as $part) {
            if ($part === '' || $part === '.') {
                continue;
            }if ($part === '..') {
                if (! $parts) {
                    return null;
                }array_pop($parts);
            } else {
                $parts[] = $part;
            }
        }$result = implode('/', $parts);

        return str_starts_with($result, 'ppt/') ? $result : null;
    }

    private function relations(string $part): array
    {
        $doc = $this->xml(dirname($part).'/_rels/'.basename($part).'.rels');
        $result = [];
        if (! $doc) {
            return $result;
        }foreach ($doc->getElementsByTagNameNS('*', 'Relationship') as $r) {
            if ($r->getAttribute('TargetMode') === 'External') {
                continue;
            }$path = $this->resolved($part, $r->getAttribute('Target'));
            if ($path) {
                $result[$r->getAttribute('Id')] = ['path' => $path, 'type' => $r->getAttribute('Type')];
            }
        }

        return $result;
    }

    private function color(?DOMNode $parent, DOMXPath $x, string $default = 'transparent'): string
    {
        if (! $parent) {
            return $default;
        }$rgb = $x->query('./a:solidFill/a:srgbClr', $parent)->item(0);
        if ($rgb instanceof DOMElement) {
            $v = $rgb->getAttribute('val');

            return preg_match('/^[a-f0-9]{6}$/i', $v) ? '#'.$v : $default;
        }
        $scheme = $x->query('./a:solidFill/a:schemeClr', $parent)->item(0);
        if ($scheme instanceof DOMElement) {
            return '#'.($this->colors[$scheme->getAttribute('val')] ?? '24473E');
        }

        return $default;
    }

    private function transform(?DOMNode $xfrm, array $parent): array
    {
        if (! $xfrm instanceof DOMElement) {
            return $parent;
        }$x = $this->xpath($xfrm->ownerDocument);
        $off = $x->query('./a:off', $xfrm)->item(0);
        $ext = $x->query('./a:ext', $xfrm)->item(0);
        $chOff = $x->query('./a:chOff', $xfrm)->item(0);
        $chExt = $x->query('./a:chExt', $xfrm)->item(0);
        $sx = $ext && $chExt ? (float) $ext->attributes->getNamedItem('cx')?->nodeValue / max(1, (float) $chExt->attributes->getNamedItem('cx')?->nodeValue) : 1;
        $sy = $ext && $chExt ? (float) $ext->attributes->getNamedItem('cy')?->nodeValue / max(1, (float) $chExt->attributes->getNamedItem('cy')?->nodeValue) : 1;

        return [$parent[0] * $sx, $parent[1] * $sy, $parent[2] + $parent[0] * ((float) ($off?->attributes?->getNamedItem('x')?->nodeValue ?? 0) - $sx * (float) ($chOff?->attributes?->getNamedItem('x')?->nodeValue ?? 0)), $parent[3] + $parent[1] * ((float) ($off?->attributes?->getNamedItem('y')?->nodeValue ?? 0) - $sy * (float) ($chOff?->attributes?->getNamedItem('y')?->nodeValue ?? 0))];
    }

    private function geometry(?DOMNode $node, DOMXPath $x, array $parent, int $index): array
    {
        $x = $node ? $this->xpath($node->ownerDocument) : $x;
        $off = $node ? $x->query('./a:off', $node)->item(0) : null;
        $ext = $node ? $x->query('./a:ext', $node)->item(0) : null;
        $left = $off ? (float) $off->attributes->getNamedItem('x')?->nodeValue : $this->width * 0.06;
        $top = $off ? (float) $off->attributes->getNamedItem('y')?->nodeValue : $this->height * (0.08 + min($index, 5) * 0.13);
        $w = $ext ? (float) $ext->attributes->getNamedItem('cx')?->nodeValue : $this->width * 0.88;
        $h = $ext ? (float) $ext->attributes->getNamedItem('cy')?->nodeValue : $this->height * 0.2;

        return ['x' => round(($left * $parent[0] + $parent[2]) / $this->width * 1000, 2), 'y' => round(($top * $parent[1] + $parent[3]) / $this->width * 1000, 2), 'width' => max(1, round($w * $parent[0] / $this->width * 1000, 2)), 'height' => max(1, round($h * $parent[1] / $this->width * 1000, 2)), 'rotation' => $node instanceof DOMElement ? ((float) $node->getAttribute('rot')) / 60000 : 0];
    }

    private function layoutTransform(DOMNode $shape, DOMXPath $x, ?DOMDocument $layout): ?DOMNode
    {
        $own = $x->query('./p:spPr/a:xfrm|./p:grpSpPr/a:xfrm', $shape)->item(0);
        if ($own || ! $layout) {
            return $own;
        }
        $ph = $x->query('./p:nvSpPr/p:nvPr/p:ph', $shape)->item(0);
        if (! $ph instanceof DOMElement) {
            return null;
        }$lx = $this->xpath($layout);
        foreach ($lx->query('//p:sp') as $s) {
            $other = $lx->query('./p:nvSpPr/p:nvPr/p:ph', $s)->item(0);
            if ($other instanceof DOMElement && (($ph->hasAttribute('idx') && $ph->getAttribute('idx') === $other->getAttribute('idx')) || (! $ph->hasAttribute('idx') && $ph->getAttribute('type') === $other->getAttribute('type')))) {
                return $lx->query('./p:spPr/a:xfrm', $s)->item(0);
            }
        }

        return null;
    }

    private function shapes(DOMNode $tree, DOMXPath $x, array $relations, ?DOMDocument $layout, array $parent = [1, 1, 0, 0]): array
    {
        $elements = [];
        $index = 0;
        foreach ($tree->childNodes as $shape) {
            if (! $shape instanceof DOMElement) {
                continue;
            }$kind = $shape->localName;
            if ($kind === 'grpSp') {
                $transform = $this->transform($x->query('./p:grpSpPr/a:xfrm', $shape)->item(0), $parent);
                $elements = [...$elements, ...$this->shapes($shape, $x, $relations, $layout, $transform)];

                continue;
            }if (! in_array($kind, ['sp', 'pic', 'cxnSp', 'graphicFrame'], true)) {
                continue;
            }$geo = $this->geometry($this->layoutTransform($shape, $x, $layout), $x, $parent, $index++);
            $properties = $x->query('./p:spPr', $shape)->item(0);
            if ($kind === 'pic') {
                $blip = $x->query('./p:blipFill/a:blip', $shape)->item(0);
                $id = $blip instanceof DOMElement ? $blip->getAttributeNS('http://schemas.openxmlformats.org/officeDocument/2006/relationships', 'embed') : '';
                $path = $relations[$id]['path'] ?? null;
                if ($path && str_starts_with($path, 'ppt/media/') && preg_match('/\.(png|jpe?g|gif|webp)$/i', $path)) {
                    $elements[] = [...$geo, 'kind' => 'image', 'asset' => hash('sha256', $path), 'alt' => 'Gambar pada slide'];
                }

                continue;
            }
            $fill = $this->color($properties, $x);
            $line = $properties ? $x->query('./a:ln', $properties)->item(0) : null;
            $stroke = $this->color($line, $x);
            $preset = $properties ? $x->query('./a:prstGeom', $properties)->item(0) : null;
            if ($fill !== 'transparent' || $stroke !== 'transparent') {
                $elements[] = [...$geo, 'kind' => 'shape', 'fill' => $fill, 'stroke' => $stroke, 'geometry' => $preset instanceof DOMElement ? $preset->getAttribute('prst') : 'rect'];
            }
            $paragraphs = [];
            foreach ($x->query('./p:txBody/a:p', $shape) as $paragraph) {
                $runs = [];
                $pr = $x->query('./a:pPr', $paragraph)->item(0);
                $defaultPr = $x->query('./a:pPr/a:defRPr', $paragraph)->item(0);
                $bullet = $x->query('./a:pPr/a:buChar', $paragraph)->item(0);
                foreach ($paragraph->childNodes as $run) {
                    if (! $run instanceof DOMElement) {
                        continue;
                    }if ($run->localName === 'br') {
                        $runs[] = ['text' => "\n"];

                        continue;
                    }if (! in_array($run->localName, ['r', 'fld'], true)) {
                        continue;
                    }$text = $x->query('./a:t', $run)->item(0)?->textContent ?? '';
                    $style = $x->query('./a:rPr', $run)->item(0) ?? $defaultPr;
                    $sz = $style instanceof DOMElement && $style->hasAttribute('sz') ? (float) $style->getAttribute('sz') / 100 : 22;
                    $runs[] = ['text' => $text, 'font_size' => max(8, min(200, $sz * 12700 / $this->width * 1000)), 'bold' => $style instanceof DOMElement && $style->getAttribute('b') === '1', 'italic' => $style instanceof DOMElement && $style->getAttribute('i') === '1', 'color' => $this->color($style, $x, '#24473E')];
                }
                if ($runs) {
                    $paragraphs[] = ['runs' => $runs, 'align' => $pr instanceof DOMElement ? (['ctr' => 'center', 'r' => 'right', 'just' => 'justify'][$pr->getAttribute('algn')] ?? 'left') : 'left', 'bullet' => $bullet instanceof DOMElement ? $bullet->getAttribute('char') : null, 'level' => $pr instanceof DOMElement ? (int) $pr->getAttribute('lvl') : 0];
                }
            }
            if ($paragraphs) {
                $body = $x->query('./p:txBody/a:bodyPr', $shape)->item(0);
                $elements[] = [...$geo, 'kind' => 'text', 'paragraphs' => $paragraphs, 'vertical_align' => $body instanceof DOMElement && $body->getAttribute('anchor') === 'ctr' ? 'center' : 'start'];
            }
            if ($kind === 'graphicFrame') {
                foreach ($x->query('.//a:tbl/a:tr', $shape) as $row) {
                    $cells = [];
                    foreach ($x->query('./a:tc', $row) as $cell) {
                        $texts = [];
                        foreach ($x->query('.//a:t', $cell) as $text) {
                            $texts[] = $text->textContent;
                        }$cells[] = implode(' ', $texts);
                    }$paragraphs[] = ['runs' => [['text' => implode(' | ', $cells), 'font_size' => 18, 'color' => '#24473E']], 'align' => 'left', 'level' => 0];
                }if ($paragraphs) {
                    $elements[] = [...$geo, 'kind' => 'text', 'paragraphs' => $paragraphs, 'vertical_align' => 'start'];
                }
            }
            abort_if(count($elements) > 1000, 422, 'Slide terlalu rumit untuk pratinjau ringan.');
        }

        return $elements;
    }

    public function read(): array
    {
        $document = $this->xml('ppt/presentation.xml');
        abort_unless($document, 422, 'Struktur presentasi tidak ditemukan.');
        $x = $this->xpath($document);
        $size = $x->query('/p:presentation/p:sldSz')->item(0);
        if ($size instanceof DOMElement) {
            $this->width = max(1, (float) $size->getAttribute('cx'));
            $this->height = max(1, (float) $size->getAttribute('cy'));
        }
        $theme = $this->xml('ppt/theme/theme1.xml');
        if ($theme) {
            $tx = $this->xpath($theme);
            foreach ($tx->query('//a:clrScheme/*') as $color) {
                $node = $color->firstChild;
                if ($node instanceof DOMElement) {
                    $v = $node->getAttribute('val') ?: $node->getAttribute('lastClr');
                    if (preg_match('/^[a-f0-9]{6}$/i', $v)) {
                        $this->colors[$color->localName] = $v;
                    }
                }
            }$this->colors['tx1'] = $this->colors['dk1'];
            $this->colors['bg1'] = $this->colors['lt1'];
        }
        abort_unless(is_finite($this->width) && is_finite($this->height) && $this->width >= 100000 && $this->height >= 100000 && $this->width <= 1000000000 && $this->height <= 1000000000 && $this->height / $this->width >= 0.2 && $this->height / $this->width <= 5, 422, 'Ukuran slide tidak didukung.');
        $relations = $this->relations('ppt/presentation.xml');
        $slides = [];
        foreach ($x->query('/p:presentation/p:sldIdLst/p:sldId') as $id) {
            abort_if(count($slides) >= 100, 422, 'Maksimum 100 slide setiap presentasi.');
            $rid = $id instanceof DOMElement ? $id->getAttributeNS('http://schemas.openxmlformats.org/officeDocument/2006/relationships', 'id') : '';
            $path = $relations[$rid]['path'] ?? null;
            if (! $path) {
                continue;
            }$slide = $this->xml($path);
            if (! $slide || $slide->documentElement?->getAttribute('show') === '0') {
                continue;
            }$sx = $this->xpath($slide);
            $tree = $sx->query('/p:sld/p:cSld/p:spTree')->item(0);
            if (! $tree) {
                continue;
            }$rels = $this->relations($path);
            $layout = null;
            foreach ($rels as $relation) {
                if (str_ends_with($relation['type'], '/slideLayout')) {
                    $layout = $this->xml($relation['path']);
                    break;
                }
            }$bg = $sx->query('/p:sld/p:cSld/p:bg/p:bgPr')->item(0);
            $elements = $this->shapes($tree, $sx, $rels, $layout);
            foreach ($elements as $element) {
                foreach (['x', 'y', 'width', 'height', 'rotation'] as $coordinate) {
                    abort_unless(is_finite($element[$coordinate]) && abs($element[$coordinate]) <= 10000000, 422, 'Koordinat bentuk pada slide tidak didukung. Gunakan PDF untuk bahan ini.');
                }
            }
            $slides[] = ['number' => count($slides) + 1, 'background' => $this->color($bg, $sx, '#FFFFFF'), 'elements' => $elements];
        }
        abort_unless(count($slides), 422, 'Tidak ada slide yang dapat ditampilkan. Gunakan PDF untuk presentasi ini.');

        return ['width' => 1000, 'height' => round($this->height / $this->width * 1000, 2), 'slides' => $slides, 'supported' => 'Teks, gambar raster, tabel sederhana dan bentuk dasar. Animasi/transisi dan tata letak kompleks PowerPoint sebaiknya disajikan sebagai PDF.'];
    }

    public function asset(string $hash): array
    {
        abort_unless(preg_match('/^[a-f0-9]{64}$/', $hash), 404);
        for ($i = 0; $i < $this->zip->numFiles; $i++) {
            $name = $this->zip->getNameIndex($i);
            if (str_starts_with($name, 'ppt/media/') && hash('sha256', $name) === $hash) {
                $bytes = $this->zip->getFromIndex($i);
                $info = $bytes !== false ? @getimagesizefromstring($bytes) : false;
                abort_unless($info && in_array($info['mime'], ['image/png', 'image/jpeg', 'image/gif', 'image/webp'], true) && $info[0] <= 8000 && $info[1] <= 8000, 404);

                return ['bytes' => $bytes, 'mime' => $info['mime']];
            }
        }abort(404);
    }
}

import { useEffect, useRef, useState } from 'react';

import { Button } from '@/components/ui/button';

/**
 * EM4: a document shown full width, page by page, so it can be read on any screen (phones included) without opening a
 * new tab. Pages are drawn with PDF.js, loaded only on this page. Zoom in and out; download for keeping. If the viewer
 * cannot start, the browser's own PDF view takes its place.
 */
export function PdfPages({ src, title }: { src: string; title: string }) {
    const box = useRef<HTMLDivElement>(null);
    const [pages, setPages] = useState<number | null>(null);
    const [zoom, setZoom] = useState(1);
    const [failed, setFailed] = useState(false);
    const doc = useRef<import('pdfjs-dist').PDFDocumentProxy | null>(null);

    useEffect(() => {
        let cancelled = false;
        void (async () => {
            try {
                const pdfjs = await import('pdfjs-dist');
                const worker =
                    await import('pdfjs-dist/build/pdf.worker.min.mjs?url');
                pdfjs.GlobalWorkerOptions.workerSrc = worker.default;
                const loaded = await pdfjs.getDocument({
                    url: src,
                    withCredentials: true,
                }).promise;
                if (!cancelled) {
                    doc.current = loaded;
                    setPages(loaded.numPages);
                }
            } catch {
                if (!cancelled) setFailed(true);
            }
        })();

        return () => {
            cancelled = true;
        };
    }, [src]);

    useEffect(() => {
        const pdf = doc.current;
        const container = box.current;
        if (!pdf || !container || pages === null) return;
        let cancelled = false;
        const draw = async () => {
            const width = Math.max(240, container.clientWidth - 32);
            const ratio = window.devicePixelRatio || 1;
            for (let n = 1; n <= pages; n++) {
                if (cancelled) return;
                const page = await pdf.getPage(n);
                const base = page.getViewport({ scale: 1 });
                const scale = (width / base.width) * zoom;
                const view = page.getViewport({ scale: scale * ratio });
                const canvas = container.querySelector<HTMLCanvasElement>(
                    `canvas[data-page="${n}"]`,
                );
                if (!canvas) continue;
                canvas.width = Math.floor(view.width);
                canvas.height = Math.floor(view.height);
                canvas.style.width = `${Math.floor(view.width / ratio)}px`;
                canvas.style.height = `${Math.floor(view.height / ratio)}px`;
                const context = canvas.getContext('2d');
                if (context) {
                    await page.render({
                        canvas,
                        canvasContext: context,
                        viewport: view,
                    }).promise;
                }
            }
        };
        void draw();
        const onResize = () => void draw();
        window.addEventListener('resize', onResize);

        return () => {
            cancelled = true;
            window.removeEventListener('resize', onResize);
        };
    }, [pages, zoom]);

    if (failed) {
        return (
            <iframe
                title={title}
                src={`${src}#view=FitH`}
                className="h-[80vh] w-full rounded-lg border bg-white"
            />
        );
    }

    return (
        <div className="grid gap-3">
            <div className="flex flex-wrap items-center gap-2">
                <span className="text-sm text-muted-foreground">
                    {pages === null
                        ? 'Opening the document…'
                        : `${pages} ${pages === 1 ? 'page' : 'pages'}`}
                </span>
                <div className="ml-auto flex items-center gap-2">
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        aria-label="Smaller"
                        disabled={zoom <= 0.75}
                        onClick={() => setZoom((z) => Math.max(0.75, z - 0.25))}
                    >
                        −
                    </Button>
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        aria-label="Larger"
                        disabled={zoom >= 2}
                        onClick={() => setZoom((z) => Math.min(2, z + 0.25))}
                    >
                        +
                    </Button>
                    <Button asChild variant="outline" size="sm">
                        <a href={src} download>
                            Download PDF
                        </a>
                    </Button>
                </div>
            </div>
            <div
                ref={box}
                className="grid max-h-[85vh] gap-4 overflow-auto rounded-lg border bg-slate-100 p-3 sm:p-4"
                aria-label={title}
                role="document"
            >
                {pages !== null &&
                    Array.from({ length: pages }, (_, i) => (
                        <div
                            key={i}
                            className="grid justify-items-center gap-1"
                        >
                            <canvas
                                data-page={i + 1}
                                className="max-w-none rounded bg-white shadow-sm"
                            />
                            <span className="text-xs text-muted-foreground">
                                Page {i + 1} of {pages}
                            </span>
                        </div>
                    ))}
            </div>
        </div>
    );
}

import { useEffect, useRef, useState } from 'react';
import type { KeyboardEvent, PointerEvent } from 'react';

import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';

export type Field = {
    key: string;
    type: string;
    page: number;
    x: number;
    y: number;
    w: number;
    h: number;
    role: string | null;
    bind: string | null;
    required: boolean;
    label: string;
};

type Props = {
    ulid: string;
    pages: number;
    pageSizes: [number, number][];
    fields: Field[];
    setFields: (fields: Field[]) => void;
    bindings: string[];
    types: string[];
    onImageUnavailable: () => void;
};

const SIGNER = ['signature', 'initials', 'date_signed'];
const tone: Record<string, string> = {
    signature: 'border-violet-500 bg-violet-500/15',
    initials: 'border-violet-400 bg-violet-400/15',
    date_signed: 'border-violet-300 bg-violet-300/15',
    checkbox: 'border-amber-500 bg-amber-500/15',
    date: 'border-sky-500 bg-sky-500/15',
    text: 'border-emerald-600 bg-emerald-500/10',
};
const MIN = 6;

type Drag = {
    mode: 'draw' | 'move' | 'resize';
    index: number;
    startX: number;
    startY: number;
    orig: Field;
};

/** Visual field placement on the form page (1 image pixel = 1 PDF point; scaled to fit). */
export function FieldCanvas({
    ulid,
    pages,
    pageSizes,
    fields,
    setFields,
    bindings,
    types,
    onImageUnavailable,
}: Props) {
    const [page, setPage] = useState(1);
    const [selected, setSelected] = useState<number | null>(null);
    const [scale, setScale] = useState(1);
    const wrap = useRef<HTMLDivElement>(null);
    const drag = useRef<Drag | null>(null);
    const [pw, ph] = pageSizes[page - 1] ?? [612, 792];

    useEffect(() => {
        const el = wrap.current;
        if (!el) return;
        // Inside a collapsed section the width is 0: keep a usable scale until it is shown.
        const fit = () =>
            setScale(
                el.clientWidth > 0 ? Math.min(1.5, el.clientWidth / pw) : 1,
            );
        fit();
        if (typeof ResizeObserver === 'undefined') return;
        const ro = new ResizeObserver(fit);
        ro.observe(el);

        return () => ro.disconnect();
    }, [pw]);

    const point = (e: PointerEvent<HTMLElement>) => {
        const box = (
            e.currentTarget.closest('[data-canvas]') as HTMLElement
        ).getBoundingClientRect();

        return {
            x: (e.clientX - box.left) / scale,
            y: (e.clientY - box.top) / scale,
        };
    };
    const clamp = (f: Field): Field => {
        const w = Math.max(MIN, Math.min(f.w, pw));
        const h = Math.max(MIN, Math.min(f.h, ph));

        return {
            ...f,
            w: Math.round(w),
            h: Math.round(h),
            x: Math.round(Math.max(0, Math.min(f.x, pw - w))),
            y: Math.round(Math.max(0, Math.min(f.y, ph - h))),
        };
    };
    const update = (i: number, patch: Partial<Field>) =>
        setFields(
            fields.map((f, j) => (j === i ? clamp({ ...f, ...patch }) : f)),
        );
    const nextKey = () => {
        let n = fields.length + 1;
        while (fields.some((f) => f.key === `field_${n}`)) n++;

        return `field_${n}`;
    };

    const startDraw = (e: PointerEvent<HTMLDivElement>) => {
        if (e.target !== e.currentTarget) return;
        const p = point(e);
        const key = nextKey();
        const field: Field = {
            key,
            label: key.replace('_', ' '),
            type: 'text',
            page,
            x: p.x,
            y: p.y,
            w: MIN,
            h: MIN,
            role: null,
            bind: null,
            required: false,
        };
        setFields([...fields, field]);
        setSelected(fields.length);
        drag.current = {
            mode: 'draw',
            index: fields.length,
            startX: p.x,
            startY: p.y,
            orig: field,
        };
        e.currentTarget.setPointerCapture?.(e.pointerId);
    };
    const startMove = (
        e: PointerEvent<HTMLElement>,
        i: number,
        mode: 'move' | 'resize',
    ) => {
        e.stopPropagation();
        setSelected(i);
        const p = point(e);
        drag.current = {
            mode,
            index: i,
            startX: p.x,
            startY: p.y,
            orig: fields[i],
        };
        (
            e.currentTarget.closest('[data-canvas]') as HTMLElement
        ).setPointerCapture?.(e.pointerId);
    };
    const onMove = (e: PointerEvent<HTMLDivElement>) => {
        const d = drag.current;
        if (!d) return;
        const p = point(e);
        const dx = p.x - d.startX;
        const dy = p.y - d.startY;
        if (d.mode === 'draw') {
            update(d.index, {
                x: Math.min(d.startX, p.x),
                y: Math.min(d.startY, p.y),
                w: Math.abs(dx),
                h: Math.abs(dy),
            });
        } else if (d.mode === 'move') {
            update(d.index, { x: d.orig.x + dx, y: d.orig.y + dy });
        } else {
            update(d.index, { w: d.orig.w + dx, h: d.orig.h + dy });
        }
    };
    const onUp = () => {
        const d = drag.current;
        drag.current = null;
        if (d?.mode === 'draw') {
            const f = fields[d.index];
            if (f && (f.w < MIN + 2 || f.h < MIN + 2))
                update(d.index, { w: 160, h: 14 }); // a click places a standard field
        }
    };
    const duplicate = (i: number) => {
        const f = fields[i];
        const copy = clamp({
            ...f,
            key: nextKey(),
            label: `${f.label} (copy)`,
            y: f.y + f.h + 4,
        });
        setFields([...fields, copy]);
        setSelected(fields.length);
    };
    const remove = (i: number) => {
        setFields(fields.filter((_, j) => j !== i));
        setSelected(null);
    };
    const onKey = (e: KeyboardEvent<HTMLDivElement>) => {
        if (
            selected === null ||
            (e.target as HTMLElement).tagName === 'INPUT' ||
            (e.target as HTMLElement).tagName === 'SELECT'
        )
            return;
        const step = e.shiftKey ? 10 : 1;
        const f = fields[selected];
        const moves: Record<string, Partial<Field>> = {
            ArrowLeft: { x: f.x - step },
            ArrowRight: { x: f.x + step },
            ArrowUp: { y: f.y - step },
            ArrowDown: { y: f.y + step },
        };
        if (moves[e.key]) {
            e.preventDefault();
            update(selected, moves[e.key]);
        } else if (e.key === 'Delete' || e.key === 'Backspace') {
            e.preventDefault();
            remove(selected);
        } else if ((e.metaKey || e.ctrlKey) && e.key.toLowerCase() === 'd') {
            e.preventDefault();
            duplicate(selected);
        }
    };
    const sel = selected !== null ? fields[selected] : null;

    return (
        <div className="grid gap-3 lg:grid-cols-[1fr_18rem]" onKeyDown={onKey}>
            <div className="grid gap-2">
                <div className="flex items-center gap-2 text-xs">
                    <Button
                        size="sm"
                        variant="outline"
                        disabled={page <= 1}
                        onClick={() => {
                            setPage(page - 1);
                            setSelected(null);
                        }}
                    >
                        Previous page
                    </Button>
                    <span>
                        Page {page} of {pages}
                    </span>
                    <Button
                        size="sm"
                        variant="outline"
                        disabled={page >= pages}
                        onClick={() => {
                            setPage(page + 1);
                            setSelected(null);
                        }}
                    >
                        Next page
                    </Button>
                    <span className="text-muted-foreground">
                        Drag on the page to add a field · drag to move · corner
                        to resize · arrows to nudge
                    </span>
                </div>
                <div
                    ref={wrap}
                    className="overflow-hidden rounded border bg-white"
                >
                    <div
                        data-canvas
                        className="relative touch-none select-none"
                        style={{ width: pw * scale, height: ph * scale }}
                        onPointerDown={startDraw}
                        onPointerMove={onMove}
                        onPointerUp={onUp}
                        tabIndex={0}
                        aria-label={`Page ${page} of the form`}
                    >
                        <img
                            src={`/forms/templates/${ulid}/pages/${page}`}
                            alt=""
                            draggable={false}
                            className="pointer-events-none absolute inset-0 h-full w-full"
                            onError={onImageUnavailable}
                        />
                        {fields.map((f, i) =>
                            f.page !== page ? null : (
                                <button
                                    key={i}
                                    type="button"
                                    aria-label={`${f.label} (${f.type})`}
                                    aria-pressed={selected === i}
                                    className={`absolute border-2 text-left text-[10px] leading-tight text-slate-800 ${tone[f.type] ?? tone.text} ${selected === i ? 'ring-2 ring-primary' : ''}`}
                                    style={{
                                        left: f.x * scale,
                                        top: f.y * scale,
                                        width: f.w * scale,
                                        height: f.h * scale,
                                    }}
                                    onPointerDown={(e) =>
                                        startMove(e, i, 'move')
                                    }
                                >
                                    <span className="block truncate px-0.5">
                                        {f.bind ? `{${f.bind}}` : f.label}
                                        {SIGNER.includes(f.type) && f.role
                                            ? ` · ${f.role}`
                                            : ''}
                                    </span>
                                    {selected === i && (
                                        <span
                                            aria-hidden
                                            className="absolute -right-1.5 -bottom-1.5 size-3 cursor-se-resize rounded-sm bg-primary"
                                            onPointerDown={(e) =>
                                                startMove(e, i, 'resize')
                                            }
                                        />
                                    )}
                                </button>
                            ),
                        )}
                    </div>
                </div>
            </div>
            <aside className="grid content-start gap-2 rounded border p-3 text-sm">
                {!sel ? (
                    <p className="text-muted-foreground">
                        Select a field, or drag on the page to add one.
                    </p>
                ) : (
                    <>
                        <p className="font-medium">Field on page {sel.page}</p>
                        <label className="grid gap-1 text-xs">
                            Label
                            <Input
                                aria-label="Field label"
                                value={sel.label}
                                onChange={(e) =>
                                    update(selected!, { label: e.target.value })
                                }
                            />
                        </label>
                        <label className="grid gap-1 text-xs">
                            Key
                            <Input
                                aria-label="Field key"
                                value={sel.key}
                                onChange={(e) =>
                                    update(selected!, {
                                        key: e.target.value
                                            .toLowerCase()
                                            .replace(/[^a-z0-9_]/g, '_'),
                                    })
                                }
                            />
                        </label>
                        <label className="grid gap-1 text-xs">
                            Type
                            <select
                                aria-label="Field type"
                                className="rounded border bg-background px-2 py-1.5"
                                value={sel.type}
                                onChange={(e) =>
                                    update(selected!, {
                                        type: e.target.value,
                                        bind: SIGNER.includes(e.target.value)
                                            ? null
                                            : sel.bind,
                                    })
                                }
                            >
                                {types.map((t) => (
                                    <option key={t} value={t}>
                                        {t.replace('_', ' ')}
                                    </option>
                                ))}
                            </select>
                        </label>
                        {SIGNER.includes(sel.type) ? (
                            <label className="grid gap-1 text-xs">
                                Signer
                                <Input
                                    aria-label="Signer role"
                                    placeholder="buyer1, seller1, listing_agent…"
                                    value={sel.role ?? ''}
                                    onChange={(e) =>
                                        update(selected!, {
                                            role: e.target.value || null,
                                        })
                                    }
                                />
                            </label>
                        ) : (
                            <label className="grid gap-1 text-xs">
                                Fill from the deal
                                <select
                                    aria-label="Fill from"
                                    className="rounded border bg-background px-2 py-1.5"
                                    value={sel.bind ?? ''}
                                    onChange={(e) =>
                                        update(selected!, {
                                            bind: e.target.value || null,
                                        })
                                    }
                                >
                                    <option value="">Typed by the agent</option>
                                    {bindings.map((b) => (
                                        <option key={b} value={b}>
                                            {b}
                                        </option>
                                    ))}
                                </select>
                            </label>
                        )}
                        {!SIGNER.includes(sel.type) && (
                            <label className="flex items-center gap-2 text-xs">
                                <input
                                    type="checkbox"
                                    className="size-4"
                                    checked={sel.required}
                                    onChange={(e) =>
                                        update(selected!, {
                                            required: e.target.checked,
                                        })
                                    }
                                />{' '}
                                Required
                            </label>
                        )}
                        <p className="text-xs text-muted-foreground">
                            x {sel.x} · y {sel.y} · {sel.w} × {sel.h} pt
                        </p>
                        <span className="flex gap-2">
                            <Button
                                size="sm"
                                variant="outline"
                                onClick={() => duplicate(selected!)}
                            >
                                Duplicate
                            </Button>
                            <Button
                                size="sm"
                                variant="ghost"
                                onClick={() => remove(selected!)}
                            >
                                Delete
                            </Button>
                        </span>
                    </>
                )}
            </aside>
        </div>
    );
}

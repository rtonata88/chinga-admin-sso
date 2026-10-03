// resources/js/pages/kulipi-kuna/ladder.tsx
//
// Ladder verify (Kulipi Kuna PRD §6, §10): the engine re-derives every level
// of one ladder from the revealed seeds and checks each seed against the
// commitment published before the hands closed.

import UserLayout from '@/layouts/user-layout';
import { Head, router } from '@inertiajs/react';
import { useState } from 'react';

import { ERROR_BOX, PANEL, TAP } from '../vrrr-pha/format';

interface Mismatch {
    roundId: number | null;
    level: number | null;
    field: string;
    expected: string;
    published: string;
}

interface Result {
    ladder_id: number;
    user_uuid: string;
    steps: number;
    valid: boolean;
    mismatches: Mismatch[];
}

interface Props {
    game: { name: string; base: string };
    ladderId: number | null;
    result: Result | null;
    /** True when the ladder has no revealed level yet: nothing to verify, no verdict. */
    pending?: boolean;
    error: string | null;
}

export default function LadderVerifyPage({ game, ladderId, result, pending = false, error }: Props) {
    const [id, setId] = useState(ladderId === null ? '' : String(ladderId));
    const submit = (e: { preventDefault: () => void }) => {
        e.preventDefault();
        const clean = id.trim();
        router.get(`${game.base}/ladder`, clean ? { id: clean } : {}, { preserveState: true });
    };

    return (
        <UserLayout title={`${game.name} verify ladder`}>
            <Head title={`${game.name} verify ladder · Admin`} />
            <div className="cgo-page">
                <div className="cgo-page-head">
                    <div>
                        <div className="cgo-eyebrow">{game.name}</div>
                        <h1 className="cgo-title">Verify a ladder</h1>
                        <div className="cgo-subtitle">
                            Every level re-derived from its revealed seed, and every seed checked against the commitment published before the hands closed.
                        </div>
                    </div>
                </div>

                <form onSubmit={submit} className="cgo-filterbar" style={{ gap: 10 }}>
                    <span className="cgo-sort-label">Ladder</span>
                    <label className={`cgo-input max-sm:flex-1 sm:min-w-[200px] ${TAP}`}>
                        <input aria-label="Ladder id" inputMode="numeric" value={id} onChange={(e) => setId(e.target.value.replace(/\D/g, ''))} placeholder="e.g. 4212" />
                    </label>
                    <button type="submit" className={`cg-btn cg-btn--sm ${TAP}`}>Verify</button>
                </form>

                {error && (
                    <div style={ERROR_BOX}>
                        <strong style={{ color: 'var(--cg-neg)' }}>Error:</strong> {error}
                    </div>
                )}

                {result && (
                    <section data-testid="ladder-verify" style={{ ...PANEL, marginTop: 18 }}>
                        <div className="cgo-eyebrow" style={{ marginBottom: 6 }}>Ladder #{result.ladder_id}</div>
                        {pending || result.steps === 0 ? (
                            <div style={{ fontSize: 15, fontWeight: 600 }}>Nothing revealed yet for this ladder.</div>
                        ) : (
                            <div style={{ fontSize: 15, fontWeight: 600, color: result.valid ? 'var(--cg-pos)' : 'var(--cg-neg)' }}>
                                {result.valid ? `Verified: all ${result.steps} revealed levels match their seeds and commitments.` : `${result.mismatches.length} mismatch${result.mismatches.length === 1 ? '' : 'es'} found.`}
                            </div>
                        )}
                        <div className="cgo-uid" style={{ marginTop: 6 }}>Player {result.user_uuid}</div>
                        {!(pending || result.steps === 0) && result.mismatches.length > 0 && (
                            <table className="cgo-wagers" style={{ marginTop: 12 }}>
                                <thead>
                                    <tr>
                                        <th>Field</th>
                                        <th>Round</th>
                                        <th>Level</th>
                                        <th>Expected</th>
                                        <th>Published</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {result.mismatches.map((m, i) => (
                                        <tr key={i}>
                                            <td>{m.field}</td>
                                            <td>{m.roundId ?? '—'}</td>
                                            <td>{m.level ?? '—'}</td>
                                            <td className="cgo-uid" style={{ wordBreak: 'break-all' }}>{m.expected}</td>
                                            <td className="cgo-uid" style={{ wordBreak: 'break-all' }}>{m.published}</td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        )}
                    </section>
                )}
            </div>
        </UserLayout>
    );
}

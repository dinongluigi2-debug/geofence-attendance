import { Camera, CameraOff, CheckCircle2, ScanLine } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';

type ScanHistory = {
    date: string | null;
    status: string;
    time: string | null;
    method: string;
    reason: string | null;
};
type Lookup = {
    student: {
        name: string;
        studentNumber: string;
        yearLevel: string;
        section: string;
    };
    history: ScanHistory[];
    canRecord: boolean;
    recordedToday: boolean;
    sessionDate: string | null;
    message: string | null;
};
type ScannerProps = { scheduleId: number };
type BarcodeDetectorShape = {
    detect: (source: HTMLVideoElement) => Promise<Array<{ rawValue: string }>>;
};
type WindowWithBarcode = Window & {
    BarcodeDetector?: new (options?: {
        formats: string[];
    }) => BarcodeDetectorShape;
};

const REASONS = [
    'GPS unavailable',
    'GPS permission denied',
    'GPS location inaccurate',
    'Other',
];

function csrfToken(): string {
    return (
        document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')
            ?.content ?? ''
    );
}

export default function QrAttendance({ scheduleId }: ScannerProps) {
    const video = useRef<HTMLVideoElement>(null);
    const stream = useRef<MediaStream | null>(null);
    const animation = useRef<number | null>(null);
    const busy = useRef(false);
    const [cameraActive, setCameraActive] = useState(false);
    const [cameraMessage, setCameraMessage] = useState('');
    const [tokenInput, setTokenInput] = useState('');
    const [token, setToken] = useState('');
    const [lookup, setLookup] = useState<Lookup | null>(null);
    const [reason, setReason] = useState(REASONS[0]);
    const [details, setDetails] = useState('');
    const [confirmed, setConfirmed] = useState(false);
    const [loading, setLoading] = useState(false);
    const [error, setError] = useState('');
    const [notice, setNotice] = useState('');

    const stopCamera = () => {
        if (animation.current !== null) {
            cancelAnimationFrame(animation.current);
        }

        animation.current = null;
        stream.current?.getTracks().forEach((track) => track.stop());
        stream.current = null;
        setCameraActive(false);
    };

    useEffect(
        () => () => {
            if (animation.current !== null) {
                cancelAnimationFrame(animation.current);
            }

            stream.current?.getTracks().forEach((track) => track.stop());
        },
        [],
    );

    const responseJson = async (response: Response) => {
        try {
            return await response.json();
        } catch {
            throw new Error(
                response.status === 419
                    ? 'Your session expired. Refresh the page and sign in again.'
                    : 'The server returned an unexpected response. Refresh the page and try again.',
            );
        }
    };

    const lookupToken = async (raw: string) => {
        const cleanToken = raw.trim();

        if (!cleanToken) {
            return;
        }

        setLoading(true);
        setError('');
        setNotice('');
        setLookup(null);
        setToken(cleanToken);

        try {
            const response = await fetch(
                `/instructor/classes/${scheduleId}/attendance/qr/lookup`,
                {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: {
                        Accept: 'application/json',
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': csrfToken(),
                    },
                    body: JSON.stringify({ token: cleanToken }),
                },
            );
            const data = await responseJson(response);

            if (!response.ok) {
                throw new Error(
                    data.message ??
                        Object.values(data.errors ?? {})
                            .flat()
                            .join(' ') ??
                        'Unable to verify this QR code.',
                );
            }

            setLookup(data as Lookup);
            setConfirmed(false);
        } catch (cause) {
            setError(
                cause instanceof Error
                    ? cause.message
                    : 'Unable to verify this QR code.',
            );
        } finally {
            setLoading(false);
        }
    };

    const startCamera = async () => {
        setCameraMessage('');
        setError('');

        if (!navigator.mediaDevices?.getUserMedia) {
            setCameraMessage(
                'Camera access is unavailable in this browser. Enter the code manually below.',
            );

            return;
        }

        try {
            const media = await navigator.mediaDevices.getUserMedia({
                video: { facingMode: { ideal: 'environment' } },
                audio: false,
            });
            stream.current = media;

            if (video.current) {
                video.current.srcObject = media;
                await video.current.play();
            }

            setCameraActive(true);
            const Detector = (window as WindowWithBarcode).BarcodeDetector;

            if (!Detector) {
                setCameraMessage(
                    'Live QR detection is not supported by this browser. Enter the code manually below.',
                );
                stopCamera();

                return;
            }

            const detector = new Detector({ formats: ['qr_code'] });
            const scan = async () => {
                if (!video.current || busy.current || !stream.current) {
                    return;
                }

                if (
                    video.current.readyState >=
                    HTMLMediaElement.HAVE_CURRENT_DATA
                ) {
                    busy.current = true;

                    try {
                        const codes = await detector.detect(video.current);

                        if (codes[0]?.rawValue) {
                            stopCamera();
                            await lookupToken(codes[0].rawValue);

                            return;
                        }
                    } catch {
                        setCameraMessage(
                            'Could not read this code. Adjust the camera or use manual code entry.',
                        );
                    } finally {
                        busy.current = false;
                    }
                }

                animation.current = requestAnimationFrame(() => void scan());
            };
            animation.current = requestAnimationFrame(() => void scan());
        } catch (cause) {
            const name = cause instanceof DOMException ? cause.name : '';
            setCameraMessage(
                name === 'NotAllowedError'
                    ? 'Camera permission was denied. Allow camera access in your browser settings or enter the code manually.'
                    : name === 'NotFoundError'
                      ? 'No camera was found. Enter the code manually below.'
                      : name === 'NotReadableError'
                        ? 'The camera is already in use by another app. Close it and try again, or enter the code manually.'
                        : 'The camera could not start. Camera access requires a secure HTTPS page or localhost. Check permissions or enter the code manually.',
            );
            stopCamera();
        }
    };

    const recordAttendance = async () => {
        setLoading(true);
        setError('');

        try {
            const response = await fetch(
                `/instructor/classes/${scheduleId}/attendance/qr/record`,
                {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: {
                        Accept: 'application/json',
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': csrfToken(),
                    },
                    body: JSON.stringify({
                        token,
                        confirm_presence: confirmed,
                        reason,
                        reason_details: details,
                    }),
                },
            );
            const data = await responseJson(response);

            if (!response.ok) {
                throw new Error(
                    data.message ??
                        Object.values(data.errors ?? {})
                            .flat()
                            .join(' ') ??
                        'Unable to record attendance.',
                );
            }

            await lookupToken(token);
            setNotice(data.message);
        } catch (cause) {
            setError(
                cause instanceof Error
                    ? cause.message
                    : 'Unable to record attendance.',
            );
        } finally {
            setLoading(false);
        }
    };

    return (
        <section className="mt-6 rounded-[22px] border border-gray-200 bg-white p-5 shadow-sm sm:p-6">
            <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <h2 className="text-xl font-bold">
                        QR Attendance Verification
                    </h2>
                    <p className="mt-1 text-sm text-slate-600">
                        Scan a student code, review class history, then confirm
                        in-person presence to record manual attendance.
                    </p>
                </div>
                <button
                    type="button"
                    onClick={
                        cameraActive ? stopCamera : () => void startCamera()
                    }
                    className="inline-flex items-center justify-center gap-2 rounded-xl bg-[#970027] px-4 py-3 font-bold text-white"
                >
                    {cameraActive ? (
                        <CameraOff size={18} />
                    ) : (
                        <Camera size={18} />
                    )}
                    {cameraActive ? 'Stop camera' : 'Start camera'}
                </button>
            </div>
            <video
                ref={video}
                muted
                playsInline
                className={`${cameraActive ? 'mt-4 block' : 'hidden'} max-h-72 w-full rounded-xl bg-black object-cover`}
            />
            {cameraMessage && (
                <p className="mt-3 rounded-lg bg-amber-50 p-3 text-sm text-amber-800">
                    {cameraMessage}
                </p>
            )}
            <form
                className="mt-4 flex flex-col gap-2 sm:flex-row"
                onSubmit={(event) => {
                    event.preventDefault();
                    void lookupToken(tokenInput);
                }}
            >
                <label className="sr-only" htmlFor="qr-token-entry">
                    Student QR code value
                </label>
                <input
                    id="qr-token-entry"
                    value={tokenInput}
                    onChange={(event) => setTokenInput(event.target.value)}
                    placeholder="Manual fallback: enter the 64-character code"
                    className="h-12 min-w-0 flex-1 rounded-xl border border-slate-300 px-4 text-sm"
                />
                <button
                    disabled={loading || !tokenInput.trim()}
                    className="inline-flex h-12 items-center justify-center gap-2 rounded-xl border border-slate-300 px-5 font-bold disabled:opacity-50"
                >
                    <ScanLine size={17} /> Verify code
                </button>
            </form>
            {error && (
                <p
                    role="alert"
                    className="mt-3 rounded-lg bg-red-50 p-3 text-sm text-red-700"
                >
                    {error}
                </p>
            )}
            {notice && (
                <p
                    role="status"
                    className="mt-3 rounded-lg bg-emerald-50 p-3 text-sm text-emerald-800"
                >
                    {notice}
                </p>
            )}
            {lookup && (
                <div className="mt-5 rounded-2xl border border-slate-200 bg-slate-50 p-4 sm:p-5">
                    <div className="flex flex-wrap items-start justify-between gap-3">
                        <div>
                            <h3 className="text-lg font-bold">
                                {lookup.student.name}
                            </h3>
                            <p className="mt-1 text-sm text-slate-700">
                                Student number: {lookup.student.studentNumber} ·
                                Year: {lookup.student.yearLevel} · Section:{' '}
                                {lookup.student.section}
                            </p>
                        </div>
                    </div>
                    <div className="mt-4">
                        <h4 className="font-bold">
                            Attendance history for this class
                        </h4>
                        {lookup.history.length ? (
                            <ul className="mt-2 divide-y divide-slate-200 rounded-lg border border-slate-200 bg-white">
                                {lookup.history.map((record, index) => (
                                    <li
                                        key={`${record.date}-${index}`}
                                        className="flex flex-wrap justify-between gap-2 px-3 py-2 text-sm"
                                    >
                                        <span>
                                            {record.date ?? 'Date unavailable'}{' '}
                                            · {record.status}
                                            {record.time
                                                ? ` at ${record.time}`
                                                : ''}
                                        </span>
                                        <span className="text-slate-600">
                                            {record.method === 'qr_manual'
                                                ? `QR/manual${record.reason ? ` · ${record.reason}` : ''}`
                                                : record.method}
                                        </span>
                                    </li>
                                ))}
                            </ul>
                        ) : (
                            <p className="mt-2 text-sm text-slate-600">
                                No attendance history recorded for this class.
                            </p>
                        )}
                    </div>
                    {lookup.recordedToday && (
                        <p className="mt-4 rounded-lg bg-emerald-50 p-3 text-sm font-semibold text-emerald-800">
                            Attendance is already recorded for this session.
                        </p>
                    )}
                    {!lookup.recordedToday && !lookup.canRecord && (
                        <p className="mt-4 rounded-lg bg-amber-50 p-3 text-sm text-amber-800">
                            {lookup.message ??
                                'Manual attendance is unavailable for this class session.'}
                        </p>
                    )}
                    {lookup.canRecord && (
                        <div className="mt-4 space-y-3 rounded-xl border border-slate-200 bg-white p-4">
                            <label className="flex items-start gap-2 text-sm font-semibold">
                                <input
                                    type="checkbox"
                                    checked={confirmed}
                                    onChange={(event) =>
                                        setConfirmed(event.target.checked)
                                    }
                                    className="mt-1"
                                />
                                I confirm that this student is physically
                                present in the classroom.
                            </label>
                            <label className="block text-sm font-semibold">
                                Reason for manual attendance
                                <select
                                    value={reason}
                                    onChange={(event) =>
                                        setReason(event.target.value)
                                    }
                                    className="mt-1 h-11 w-full rounded-lg border border-slate-300 px-3 font-normal"
                                >
                                    {REASONS.map((value) => (
                                        <option key={value}>{value}</option>
                                    ))}
                                </select>
                            </label>
                            {reason === 'Other' && (
                                <label className="block text-sm font-semibold">
                                    Reason details
                                    <textarea
                                        value={details}
                                        onChange={(event) =>
                                            setDetails(event.target.value)
                                        }
                                        minLength={5}
                                        maxLength={500}
                                        required
                                        className="mt-1 min-h-20 w-full rounded-lg border border-slate-300 p-3 font-normal"
                                        placeholder="Explain why GPS check-in was unsuitable"
                                    />
                                </label>
                            )}
                            <button
                                type="button"
                                disabled={
                                    !confirmed ||
                                    loading ||
                                    (reason === 'Other' &&
                                        details.trim().length < 5)
                                }
                                onClick={() => void recordAttendance()}
                                className="inline-flex w-full items-center justify-center gap-2 rounded-xl bg-emerald-700 px-4 py-3 font-bold text-white disabled:cursor-not-allowed disabled:opacity-50"
                            >
                                <CheckCircle2 size={18} /> Confirm and record
                                attendance
                            </button>
                        </div>
                    )}
                </div>
            )}
        </section>
    );
}

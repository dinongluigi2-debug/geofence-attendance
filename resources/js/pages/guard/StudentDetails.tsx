import { Head, Link, usePage } from '@inertiajs/react';
import { ArrowLeft, CalendarDays } from 'lucide-react';
import GuardLayout from '@/layouts/guard-layout';

type Schedule = {
    id: number;
    subject: string;
    instructor: string;
    location: string | null;
    section: string | null;
    days: string[];
    start_time: string;
    end_time: string;
    state: string;
    attendance_status: string;
    next_session: { start: string; end: string } | null;
};
type Props = {
    student: {
        id: number;
        name: string;
        student_id: string | null;
        course: string | null;
        year: string | null;
        section: string | null;
    };
    schedules: Schedule[];
};

export default function StudentDetails() {
    const { student, schedules } = usePage<Props>().props;

    return (
        <>
            <Head title="Authorized Student Inquiry" />
            <GuardLayout
                title="Authorized Student Inquiry"
                subtitle="Class schedule and attendance status for this inquiry"
            >
                <div className="w-full min-w-0 space-y-5 p-4 text-gray-900 sm:p-6 lg:p-8">
                    <Link
                        href="/guard/student-tracking"
                        className="inline-flex items-center gap-2 font-semibold text-[#7d0b1c]"
                    >
                        <ArrowLeft size={18} /> Back to student search
                    </Link>
                    <p className="rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm leading-6 text-amber-900">
                        Share these details only after confirming the
                        requester’s identity and authority under campus
                        procedure. This disclosure has been recorded.
                    </p>
                    <section className="rounded-2xl border border-[#ecd9dc] bg-white p-5 shadow-sm">
                        <h2 className="text-xl font-bold text-[#7d0b1c]">
                            {student.name}
                        </h2>
                        <dl className="mt-4 grid gap-3 text-sm sm:grid-cols-2 lg:grid-cols-4">
                            {[
                                ['Student number', student.student_id],
                                ['Course', student.course],
                                ['Year level', student.year],
                                ['Section', student.section],
                            ].map(([label, value]) => (
                                <div key={label}>
                                    <dt className="font-semibold text-gray-500">
                                        {label}
                                    </dt>
                                    <dd className="mt-1">{value || 'Not set'}</dd>
                                </div>
                            ))}
                        </dl>
                    </section>
                    <section className="rounded-2xl border border-[#ecd9dc] bg-white p-5 shadow-sm">
                        <h2 className="flex items-center gap-2 text-lg font-bold">
                            <CalendarDays size={20} /> Relevant class schedule
                            and today’s attendance status
                        </h2>
                        <p className="mt-1 text-sm text-gray-500">
                            Only active class schedules and today’s status are
                            shown. Historical attendance is not available in
                            the Guard Portal.
                        </p>
                        <div className="mt-4 space-y-3">
                            {schedules.map((item) => (
                                <article
                                    key={item.id}
                                    className="rounded-xl border border-gray-200 p-4"
                                >
                                    <div className="flex flex-wrap items-start justify-between gap-2">
                                        <h3 className="font-bold">
                                            {item.subject}
                                        </h3>
                                        <span className="rounded-full bg-slate-100 px-3 py-1 text-xs font-semibold">
                                            {item.state}
                                        </span>
                                    </div>
                                    <p className="mt-2 text-sm text-gray-600">
                                        Section: {item.section || 'Not set'} ·{' '}
                                        {item.days.join(', ') || 'Days not set'} ·{' '}
                                        {item.start_time}-{item.end_time}
                                    </p>
                                    {(item.state === 'Ongoing' ||
                                        item.state === 'Pending') && (
                                        <p className="mt-1 text-sm text-gray-600">
                                            Expected classroom:{' '}
                                            {item.location || 'Not set'}
                                        </p>
                                    )}
                                    <p className="mt-2 text-sm">
                                        Today’s attendance:{' '}
                                        <strong>{item.attendance_status}</strong>
                                    </p>
                                </article>
                            ))}
                            {!schedules.length && (
                                <p className="rounded-lg bg-gray-50 p-4 text-sm text-gray-600">
                                    No active class schedule is available for
                                    this student.
                                </p>
                            )}
                        </div>
                    </section>
                </div>
            </GuardLayout>
        </>
    );
}

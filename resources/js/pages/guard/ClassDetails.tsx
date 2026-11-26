import { Head, Link, usePage } from '@inertiajs/react';
import { ArrowLeft, CalendarDays, Clock3, MapPin, Users } from 'lucide-react';
import GuardLayout from '@/layouts/guard-layout';

type ClassDetails = {
    id: number;
    subject: string;
    section: string;
    instructor: string;
    startTime: string;
    endTime: string;
    location: string;
    status: string;
};
type Statistics = {
    total: number;
    present: number;
    late: number;
    absent: number;
};
type PageProps = {
    classDetails: ClassDetails;
    statistics: Statistics;
};

export default function GuardClassDetailsPage() {
    const { classDetails, statistics } = usePage<PageProps>().props;

    return (
        <>
            <Head title={`${classDetails.subject} schedule`} />
            <GuardLayout
                title="Class Schedule"
                subtitle={`${classDetails.subject} · ${classDetails.section}`}
            >
                <div className="space-y-5 p-4 sm:p-6 lg:p-8">
                    <Link
                        href="/guard/dashboard"
                        className="inline-flex items-center gap-2 font-semibold text-[#7d0b1c]"
                    >
                        <ArrowLeft size={18} /> Back to class overview
                    </Link>
                    <section className="rounded-2xl border border-[#ecd9dc] bg-white p-5 shadow-sm">
                        <div className="flex flex-wrap items-start justify-between gap-3">
                            <div>
                                <p className="text-sm font-semibold text-[#8a3d4c]">
                                    {classDetails.subject}
                                </p>
                                <h2 className="mt-1 text-2xl font-bold text-[#2d0b12]">
                                    {classDetails.section}
                                </h2>
                            </div>
                            <span className="rounded-full bg-[#f8e3e7] px-3 py-1 text-sm font-semibold text-[#7d0b1c]">
                                {classDetails.status}
                            </span>
                        </div>
                        <dl className="mt-5 grid gap-4 text-sm sm:grid-cols-2 lg:grid-cols-4">
                            <div>
                                <dt className="flex items-center gap-2 font-semibold text-gray-500">
                                    <Clock3 size={16} /> Time
                                </dt>
                                <dd className="mt-1">
                                    {classDetails.startTime} – {classDetails.endTime}
                                </dd>
                            </div>
                            <div>
                                <dt className="flex items-center gap-2 font-semibold text-gray-500">
                                    <MapPin size={16} /> Expected classroom
                                </dt>
                                <dd className="mt-1">
                                    {classDetails.location || 'Not set'}
                                </dd>
                            </div>
                            <div>
                                <dt className="flex items-center gap-2 font-semibold text-gray-500">
                                    <CalendarDays size={16} /> Instructor
                                </dt>
                                <dd className="mt-1">
                                    {classDetails.instructor || 'Not set'}
                                </dd>
                            </div>
                            <div>
                                <dt className="flex items-center gap-2 font-semibold text-gray-500">
                                    <Users size={16} /> Enrolled students
                                </dt>
                                <dd className="mt-1">{statistics.total}</dd>
                            </div>
                        </dl>
                    </section>
                    <section className="rounded-2xl border border-[#ecd9dc] bg-white p-5 shadow-sm">
                        <h2 className="text-lg font-bold">Today’s attendance summary</h2>
                        <div className="mt-4 grid gap-3 sm:grid-cols-3">
                            {[
                                ['Present', statistics.present, 'text-emerald-700'],
                                ['Late', statistics.late, 'text-amber-700'],
                                ['Not recorded / absent', statistics.absent, 'text-rose-700'],
                            ].map(([label, value, color]) => (
                                <div key={label} className="rounded-xl bg-gray-50 p-4">
                                    <p className="text-sm font-medium text-gray-600">{label}</p>
                                    <p className={`mt-2 text-2xl font-bold ${color}`}>{value}</p>
                                </div>
                            ))}
                        </div>
                        <p className="mt-4 text-sm leading-6 text-gray-600">
                            Student details are available only through a name or
                            student-number inquiry. Confirm the requester’s identity
                            and authority under campus procedure before searching or
                            sharing any result.
                        </p>
                        <Link
                            href="/guard/student-tracking"
                            className="mt-4 inline-flex rounded-xl bg-[#7d0b1c] px-5 py-3 font-semibold text-white"
                        >
                            Search for a student
                        </Link>
                    </section>
                </div>
            </GuardLayout>
        </>
    );
}

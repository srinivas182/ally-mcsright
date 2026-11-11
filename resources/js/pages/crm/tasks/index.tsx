import { Head } from '@inertiajs/react';

import { AddTask, TaskList, type TaskRow } from '@/components/crm/task-list';
import { EmptyState } from '@/components/ui/empty-state';
import { PageHeader } from '@/components/ui/page-header';

type Props = {
    overdue: TaskRow[];
    today: TaskRow[];
    upcoming: TaskRow[];
    done: TaskRow[];
};

function Group({ title, tasks }: { title: string; tasks: TaskRow[] }) {
    if (tasks.length === 0) {
        return null;
    }
    return (
        <section className="grid gap-2">
            <h2 className="text-sm font-semibold text-muted-foreground">
                {title} ({tasks.length})
            </h2>
            <TaskList tasks={tasks} />
        </section>
    );
}

export default function TasksIndex({ overdue, today, upcoming, done }: Props) {
    const nothing = overdue.length + today.length + upcoming.length === 0;

    return (
        <>
            <Head title="My tasks" />
            <div className="flex flex-col gap-6 p-4 md:p-6">
                <PageHeader
                    title="My tasks"
                    description={
                        overdue.length > 0
                            ? `${overdue.length} overdue — start there.`
                            : 'What needs you today.'
                    }
                />
                <AddTask />
                {nothing && (
                    <EmptyState
                        title="You're all caught up"
                        description="Add a task above or from any contact."
                    />
                )}
                <Group title="Overdue" tasks={overdue} />
                <Group title="Today" tasks={today} />
                <Group title="Upcoming" tasks={upcoming} />
                <Group title="Recently done" tasks={done} />
            </div>
        </>
    );
}

TasksIndex.layout = { breadcrumbs: [{ title: 'My tasks', href: '/tasks' }] };

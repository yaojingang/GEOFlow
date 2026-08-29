@forelse ($recentJobs as $job)
    <x-admin.task-monitoring.job-row :job="$job" />
@empty
    <div class="px-6 py-10 text-center text-sm text-gray-500">{{ __('admin.tasks.jobs.none') }}</div>
@endforelse

@forelse ($workers as $worker)
    <x-admin.task-monitoring.worker-row :worker="$worker" />
@empty
    <div class="px-6 py-10 text-center text-sm text-gray-500">{{ __('admin.tasks.worker.none') }}</div>
@endforelse

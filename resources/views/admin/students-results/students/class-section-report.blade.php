@extends('layouts.admin')

@section('title', 'Class & Section Report')

@section('content')
    {{-- One line per year group, its arms listed inside it, and what the year comes to.
         The sections are links because a figure nobody can follow is a figure nobody can
         check — and because the next question, once the office has the count, is always
         "which ones?". --}}
    <div class="card overflow-hidden">
        <div class="border-b border-line bg-surface-2 px-5 py-4">
            <p class="font-display text-base font-semibold text-ink">Class &amp; Section Report</p>
        </div>

        @if ($rows->isEmpty())
            <x-empty-state
                icon="columns"
                title="No year groups yet"
                description="A class is a year group and a section put together — JSS1 and A are JSS1A. Create the classes the school runs and each one appears here with the number of students in it." />
        @else
            <div class="overflow-x-auto">
                <table class="w-full border-collapse border border-line text-sm">
                    <thead>
                        <tr class="bg-surface-3 text-left font-semibold text-ink-soft">
                            <th class="w-14 border-b border-r border-line p-3 text-center">Sl</th>
                            <th class="w-40 border-b border-r border-line p-3">Class</th>
                            <th class="border-b border-r border-line p-3">Section</th>
                            <th class="w-40 border-b border-line p-3 text-right">Total Students</th>
                        </tr>
                    </thead>

                    <tbody class="divide-y divide-line text-ink-soft">
                        @foreach ($rows as $row)
                            <tr class="align-top transition-colors hover:bg-surface-3/60">
                                <td class="border-r border-line p-3 text-center">{{ $loop->iteration }}</td>

                                <td class="border-r border-line p-3 font-medium text-ink">
                                    {{ $row['level']->name }}
                                </td>

                                <td class="border-r border-line p-3">
                                    @if ($row['classes']->isEmpty() && $row['unplaced'] === 0)
                                        <span class="text-muted">No classes yet</span>
                                    @else
                                        <ul class="space-y-0.5">
                                            @foreach ($row['classes'] as $class)
                                                <li>
                                                    {{-- An arm with nobody in it is listed
                                                         rather than omitted: an empty section
                                                         is one of the things this report is
                                                         read to find out. --}}
                                                    <a href="{{ route('admin.students-results.students', ['class' => $row['level']->id, 'section' => $class['section_id']]) }}"
                                                       title="Read {{ $row['level']->name }}{{ $class['name'] }} on the register"
                                                       class="rounded text-brand-700 hover:underline dark:text-brand-300">
                                                        {{ $class['name'] }} ({{ $class['count'] }})
                                                    </a>
                                                </li>
                                            @endforeach

                                            @if ($row['unplaced'] > 0)
                                                {{-- Inside the total below, and said out loud
                                                     here: a figure that does not add up to the
                                                     lines printed beside it is worse than no
                                                     figure at all. --}}
                                                <li class="text-muted">
                                                    {{ $row['unplaced'] }} not yet placed in a class
                                                </li>
                                            @endif
                                        </ul>
                                    @endif
                                </td>

                                <td class="border-line p-3 text-right font-semibold text-ink">
                                    {{ number_format($row['total']) }}
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>
@endsection

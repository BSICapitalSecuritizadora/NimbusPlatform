@php
    /** @var list<array{0: string, 1: string, 2: string}> $rows rótulo, antes, depois */
    $beforeLabel ??= 'Vigente hoje';
    $afterLabel ??= 'Depois da correção';
@endphp

<div class="pu-detail-scroll">
    <table class="pu-detail-table pu-before-after">
        <thead>
            <tr>
                <th scope="col"><span class="sr-only">Campo</span></th>
                <th scope="col">{{ $beforeLabel }}</th>
                <th scope="col">{{ $afterLabel }}</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($rows as [$label, $before, $after])
                <tr @class(['pu-changed' => $after !== '' && $before !== $after])>
                    <th scope="row">{{ $label }}</th>
                    <td class="pu-num">{{ $before }}</td>
                    <td class="pu-num">
                        {{ $after }}
                        @if ($after !== '' && $before !== $after)
                            <span class="pu-change-tag">alterado</span>
                        @endif
                    </td>
                </tr>
            @endforeach
        </tbody>
    </table>
</div>

@extends(view()->exists('layouts.app') ? 'layouts.app' : 'laraslice::layout')

@section('title', 'Slice Explorer'.(isset($schema) && $schema ? ' · '.$schema['name'] : ''))

@section('content')
{{-- Proposal #2's executable form (Explorer pattern): the form
     below is GENERATED from the slice's schema contract — no
     per-slice markup exists. READ invocations are fetch() calls
     from this page to the slice's OWN routes with the signed-in
     session. WRITE invocations never fetch a slice route
     directly (Proposal #4): they propose to the confirmation
     endpoints, render the returned approval spec, and execute
     through the confirm controller only after the human
     approves. --}}
<div class="mx-auto w-full max-w-[1200px] px-3 py-4 sm:px-6 lg:px-8" data-csrf="{{ csrf_token() }}"@if(! empty($schema)) data-slice="{{ $schema['id'] }}" data-propose-url="{{ route('laraslice.studio.confirm_propose') }}" data-execute-url="{{ route('laraslice.studio.confirm_execute') }}"@endif>
    @if($schema === null)
        <h1 class="text-xl font-semibold">Slice Explorer</h1>
        <div class="mt-4 rounded border border-red-300 bg-red-50 p-4">
            <p class="font-medium text-red-800">This call was rejected — here is exactly what to fix:</p>
            <ul class="mt-2 list-disc pl-5 text-sm text-red-800">
                @foreach($issues as $issue)
                    <li><strong>{{ $issue['field'] }}</strong>: {{ $issue['problem'] }} <em>Expected: {{ $issue['expected'] }}</em></li>
                @endforeach
            </ul>
            <p class="mt-3 text-sm"><a class="underline" href="{{ route('laraslice.studio.metadata') }}">Open the metadata index</a> to pick a slice id, name or handle.</p>
        </div>
    @else
        <div class="flex flex-wrap items-baseline gap-x-3 gap-y-1">
            <h1 class="text-xl font-semibold">{{ $schema['title'] }}</h1>
            <span class="text-sm text-gray-500">{{ $schema['name'] }} · {{ $schema['domain'] }} · {{ $schema['status'] }}</span>
        </div>
        <p class="mt-1 text-sm text-gray-600">
            Schema contract <code>{{ $schema['schema'] }}/v{{ $schema['schema_version'] }}</code> ·
            primary table <code>{{ $schema['table'] }}</code> ·
            <a class="underline" href="{{ route('laraslice.studio.metadata_slice', ['slice' => $schema['id']]) }}">describe JSON</a> ·
            <a class="underline" href="{{ route('laraslice.studio.metadata_schema', ['slice' => $schema['id']]) }}">schema JSON</a>
        </p>
        <p class="mt-2 max-w-3xl text-sm text-gray-600">
            Operations below are invoked against the slice's own routes with your session — the app's
            permissions and validation apply, and validation errors come back per field so you can
            correct and retry. Operations whose payload the schema cannot describe are inspect-only.
            <strong>Write operations are two-phase (Proposal #4):</strong> invoking one first prepares a
            proposal — nothing executes — and the write runs only after you review and approve it below.
            The approval is a signed token bound to exactly the values shown, valid for a few minutes
            and usable once.
        </p>

        <h2 class="mt-6 text-base font-semibold">Fields (from the schema)</h2>
        <div class="mt-2 overflow-x-auto rounded border">
            <table class="w-full text-left text-sm">
                <thead class="bg-gray-50"><tr>
                    <th class="px-3 py-2">Field</th><th class="px-3 py-2">Label</th><th class="px-3 py-2">Type</th>
                    <th class="px-3 py-2">Required</th><th class="px-3 py-2">Values / references</th>
                </tr></thead>
                <tbody>
                @foreach($schema['fields'] as $field)
                    <tr class="border-t">
                        <td class="px-3 py-2 font-mono">{{ $field['name'] }}</td>
                        <td class="px-3 py-2">{{ $field['label'] }}</td>
                        <td class="px-3 py-2">{{ $field['type'] }}</td>
                        <td class="px-3 py-2">{{ ! empty($field['required']) ? 'yes' : '—' }}</td>
                        <td class="px-3 py-2">
                            @if(isset($field['enum'])) <span class="font-mono text-xs">{{ implode(', ', array_map('strval', $field['enum'])) }}</span> @endif
                            @if(! empty($field['enum_truncated'])) <span class="text-amber-700">{{ $field['enum_count'] }} declared values (above the served cap of 50)</span> @endif
                            @if(! empty($field['relation'])) <span class="text-gray-500">→ {{ $field['relation'] }}</span> @endif
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>

        <h2 class="mt-8 text-base font-semibold">Operations</h2>
        <div class="mt-3 space-y-4">
            @foreach($forms as $i => $form)
                @php($op = $form['operation'])
                <div class="rounded border p-4" data-explorer-form="{{ $i }}">
                    <div class="flex flex-wrap items-center gap-2">
                        <span class="rounded bg-gray-800 px-2 py-0.5 font-mono text-xs text-white">{{ $op['method'] }}</span>
                        <code class="text-sm">{{ $op['uri'] }}</code>
                        <span class="text-xs text-gray-500">{{ $op['name'] }}</span>
                        <span class="rounded bg-gray-100 px-2 py-0.5 text-xs">{{ $form['kind'] }}{{ $form['invocable'] ? '' : ' · inspect-only' }}</span>
                    </div>
                    @if(! $form['invocable'])
                        <p class="mt-2 text-sm text-gray-600">{{ $form['note'] }}</p>
                    @else
                        <form class="mt-3 space-y-3" onsubmit="return explorerInvoke(event, {{ $i }})">
                            @if($form['id_param'])
                                <label class="block text-sm">
                                    <span class="font-medium">{{ $form['id_param'] }}</span>
                                    <input type="text" data-id-input class="mt-1 block w-64 rounded border px-2 py-1" required>
                                </label>
                            @endif
                            @foreach($form['fields'] as $input)
                                <label class="block text-sm">
                                    <span class="font-medium">{{ $input['label'] }}</span>
                                    @if(! empty($input['required'])) <span class="text-red-600">*</span> @endif
                                    @if($input['input'] === 'select')
                                        <select data-field="{{ $input['name'] }}" class="mt-1 block w-80 rounded border px-2 py-1">
                                            @if(empty($input['required']))
                                                <option value="">—</option>
                                            @endif
                                            @foreach($input['options'] as $option)
                                                <option value="{{ $option['value'] }}">{{ $option['label'] }}</option>
                                            @endforeach
                                        </select>
                                    @elseif($input['input'] === 'textarea')
                                        <textarea data-field="{{ $input['name'] }}" rows="2" class="mt-1 block w-80 rounded border px-2 py-1"></textarea>
                                    @elseif($input['input'] === 'number')
                                        <input type="number" step="any" data-field="{{ $input['name'] }}" class="mt-1 block w-80 rounded border px-2 py-1">
                                    @else
                                        <input type="{{ $input['input'] }}" data-field="{{ $input['name'] }}" class="mt-1 block w-80 rounded border px-2 py-1">
                                    @endif
                                    @if($input['note']) <span class="mt-1 block text-xs text-gray-500">{{ $input['note'] }}</span> @endif
                                    <span class="mt-1 hidden text-xs text-red-700" data-field-error="{{ $input['name'] }}"></span>
                                </label>
                            @endforeach
                            <button type="submit" class="rounded bg-blue-600 px-3 py-1.5 text-sm text-white">{{ in_array($form['kind'], ['create', 'update', 'delete'], true) ? 'Prepare '.$op['method'].' — review & confirm' : 'Invoke '.$op['method'] }}</button>
                        </form>
                        <div class="mt-3 hidden rounded border border-amber-300 bg-amber-50 p-3" data-approval>
                            <p class="text-sm font-semibold">Confirm this write — nothing has executed yet</p>
                            <div class="mt-2 space-y-1 text-sm" data-approval-body></div>
                            <div class="mt-3 flex gap-2" data-approval-actions>
                                <button type="button" class="rounded bg-red-600 px-3 py-1.5 text-sm text-white" onclick="return explorerApprove({{ $i }})">Approve &amp; execute</button>
                                <button type="button" class="rounded bg-gray-200 px-3 py-1.5 text-sm" onclick="return explorerCancel({{ $i }})">Cancel</button>
                            </div>
                        </div>
                        <div class="mt-3 hidden" data-result>
                            <p class="text-sm font-medium" data-result-status></p>
                            <pre class="mt-1 max-h-64 overflow-auto rounded bg-gray-900 p-3 text-xs text-gray-100" data-result-body></pre>
                        </div>
                    @endif
                </div>
            @endforeach
        </div>

        @php($explorerFormsData = collect($forms)->map(fn ($f) => ['method' => $f['operation']['method'], 'uri' => $f['operation']['uri'], 'name' => $f['operation']['name'], 'kind' => $f['kind'], 'id_param' => $f['id_param']])->values())
        <script>
            const explorerForms = @json($explorerFormsData);
            const explorerWriteKinds = ['create', 'update', 'delete'];
            const explorerPending = {};

            function explorerInvoke(event, index) {
                event.preventDefault();
                const spec = explorerForms[index];
                if (explorerWriteKinds.indexOf(spec.kind) !== -1) {
                    return explorerPropose(index);
                }
                const card = document.querySelector('[data-explorer-form="' + index + '"]');
                const csrf = document.querySelector('[data-csrf]').getAttribute('data-csrf');

                let uri = spec.uri;
                if (spec.id_param) {
                    const idValue = card.querySelector('[data-id-input]').value.trim();
                    uri = uri.replace('{' + spec.id_param + '}', encodeURIComponent(idValue));
                }

                const body = {};
                card.querySelectorAll('[data-field]').forEach((el) => {
                    if (el.value !== '') { body[el.getAttribute('data-field')] = el.value; }
                });
                card.querySelectorAll('[data-field-error]').forEach((el) => { el.classList.add('hidden'); el.textContent = ''; });

                const options = {
                    method: spec.method,
                    headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': csrf },
                    credentials: 'same-origin',
                };
                if (spec.method !== 'GET' && spec.method !== 'DELETE') {
                    options.headers['Content-Type'] = 'application/json';
                    options.body = JSON.stringify(body);
                }

                const result = card.querySelector('[data-result]');
                const status = card.querySelector('[data-result-status]');
                const pre = card.querySelector('[data-result-body]');
                result.classList.remove('hidden');
                status.textContent = 'Invoking…';

                fetch(uri, options).then(async (response) => {
                    const text = await response.text();
                    status.textContent = 'HTTP ' + response.status + ' — ' + spec.method + ' ' + uri;
                    let parsed = null;
                    try { parsed = JSON.parse(text); } catch (e) { /* not JSON */ }
                    if (response.status === 422 && parsed && parsed.errors) {
                        Object.keys(parsed.errors).forEach((field) => {
                            const slot = card.querySelector('[data-field-error="' + field + '"]');
                            if (slot) { slot.textContent = parsed.errors[field].join(' '); slot.classList.remove('hidden'); }
                        });
                        pre.textContent = 'Validation failed — fix the fields marked above and retry.\n\n' + JSON.stringify(parsed.errors, null, 2);
                    } else {
                        pre.textContent = parsed ? JSON.stringify(parsed, null, 2).slice(0, 6000) : text.slice(0, 6000);
                    }
                }).catch((error) => {
                    status.textContent = 'Request failed before a response: ' + error.message;
                    pre.textContent = '';
                });
                return false;
            }

            // ---- Proposal #4: the write two-step -----------------
            // A write card never fetches its route directly. It
            // PROPOSES (server issues a signed token + approval
            // spec, nothing executes), renders the spec for the
            // human, and only on Approve calls EXECUTE with the
            // token and exactly the proposed arguments.

            function explorerGatherCall(card, spec) {
                let uri = spec.uri;
                if (spec.id_param) {
                    const idValue = card.querySelector('[data-id-input]').value.trim();
                    uri = uri.replace('{' + spec.id_param + '}', encodeURIComponent(idValue));
                }
                const body = {};
                card.querySelectorAll('[data-field]').forEach((el) => {
                    if (el.value !== '') { body[el.getAttribute('data-field')] = el.value; }
                });

                return { uri: uri, body: body };
            }

            // Render an approval spec (the catalog vocabulary).
            // All component data is inserted as text; an unknown
            // component degrades to a labelled note, never to
            // broken markup.
            function explorerRenderSpec(container, spec) {
                container.textContent = '';
                (spec.components || []).forEach((component) => {
                    const row = document.createElement('div');
                    if (component.type === 'operation') {
                        const head = document.createElement('strong');
                        head.textContent = (component.method ? component.method + ' ' : '') + component.name + (component.uri ? ' — ' + component.uri : '');
                        row.appendChild(head);
                        if (component.destructive) {
                            const warn = document.createElement('span');
                            warn.className = 'ml-2 font-medium text-red-700';
                            warn.textContent = 'destructive';
                            row.appendChild(warn);
                        }
                    } else if (component.type === 'field') {
                        const label = document.createElement('span');
                        label.className = 'font-medium';
                        label.textContent = component.label + ': ';
                        const value = document.createElement('span');
                        value.className = 'font-mono';
                        value.textContent = component.value;
                        row.appendChild(label);
                        row.appendChild(value);
                    } else if (component.type === 'warning') {
                        row.className = component.level === 'destructive' ? 'font-medium text-red-800' : 'text-amber-800';
                        row.textContent = component.text;
                    } else if (component.type === 'note') {
                        row.className = 'text-gray-600';
                        row.textContent = component.text;
                    } else if (component.type === 'expiry') {
                        row.className = 'text-xs text-gray-500';
                        row.textContent = 'This proposal expires at ' + component.expires_at + ' (' + component.ttl_seconds + ' seconds) and can be used once.';
                    } else {
                        row.className = 'italic text-gray-500';
                        row.textContent = 'Unknown spec component: ' + component.type;
                    }
                    container.appendChild(row);
                });
            }

            function explorerRenderIssues(container, issues) {
                container.textContent = '';
                const intro = document.createElement('p');
                intro.className = 'font-medium text-red-800';
                intro.textContent = 'Rejected — here is exactly what to fix:';
                container.appendChild(intro);
                const list = document.createElement('ul');
                list.className = 'list-disc pl-5 text-red-800';
                (issues || []).forEach((issue) => {
                    const item = document.createElement('li');
                    item.textContent = issue.field + ': ' + issue.problem + ' Expected: ' + issue.expected;
                    list.appendChild(item);
                });
                container.appendChild(list);
            }

            function explorerPropose(index) {
                const card = document.querySelector('[data-explorer-form="' + index + '"]');
                const spec = explorerForms[index];
                const wrap = document.querySelector('[data-csrf]');
                const call = explorerGatherCall(card, spec);
                const panel = card.querySelector('[data-approval]');
                const body = card.querySelector('[data-approval-body]');
                panel.classList.remove('hidden');
                body.textContent = 'Preparing proposal…';

                fetch(wrap.getAttribute('data-propose-url'), {
                    method: 'POST',
                    headers: { 'Accept': 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': wrap.getAttribute('data-csrf') },
                    credentials: 'same-origin',
                    body: JSON.stringify({ slice: wrap.getAttribute('data-slice'), operation: spec.name, uri: call.uri, args: call.body }),
                }).then(async (response) => {
                    const parsed = JSON.parse(await response.text());
                    if (! parsed.success) {
                        delete explorerPending[index];
                        explorerRenderIssues(body, parsed.issues);
                        return;
                    }
                    explorerPending[index] = { token: parsed.confirmation.token, uri: call.uri, args: call.body };
                    explorerRenderSpec(body, parsed.spec);
                }).catch((error) => {
                    body.textContent = 'Proposal request failed before a response: ' + error.message;
                });
                return false;
            }

            function explorerApprove(index) {
                const card = document.querySelector('[data-explorer-form="' + index + '"]');
                const spec = explorerForms[index];
                const wrap = document.querySelector('[data-csrf]');
                const pending = explorerPending[index];
                const body = card.querySelector('[data-approval-body]');
                if (! pending) {
                    body.textContent = 'No live proposal — invoke the operation again to prepare one.';
                    return false;
                }

                fetch(wrap.getAttribute('data-execute-url'), {
                    method: 'POST',
                    headers: { 'Accept': 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': wrap.getAttribute('data-csrf') },
                    credentials: 'same-origin',
                    body: JSON.stringify({ confirmation_token: pending.token, slice: wrap.getAttribute('data-slice'), operation: spec.name, uri: pending.uri, args: pending.args }),
                }).then(async (response) => {
                    const parsed = JSON.parse(await response.text());
                    if (parsed.executed) {
                        delete explorerPending[index];
                        card.querySelector('[data-approval]').classList.add('hidden');
                        const result = card.querySelector('[data-result]');
                        const status = card.querySelector('[data-result-status]');
                        const pre = card.querySelector('[data-result-body]');
                        result.classList.remove('hidden');
                        status.textContent = 'HTTP ' + parsed.status + ' — ' + spec.method + ' ' + pending.uri + ' (confirmed write)';
                        const inner = (parsed.response && typeof parsed.response === 'object') ? parsed.response : null;
                        if (parsed.status === 422 && inner && inner.errors) {
                            Object.keys(inner.errors).forEach((field) => {
                                const slot = card.querySelector('[data-field-error="' + field + '"]');
                                if (slot) { slot.textContent = inner.errors[field].join(' '); slot.classList.remove('hidden'); }
                            });
                            pre.textContent = 'Validation failed — fix the fields marked above, then invoke again for a new proposal.\n\n' + JSON.stringify(inner.errors, null, 2);
                        } else {
                            pre.textContent = inner ? JSON.stringify(inner, null, 2).slice(0, 6000) : String(parsed.response).slice(0, 6000);
                        }
                    } else {
                        // Rejected (expired, already used, changed
                        // arguments…): the proposal is dead — show
                        // why; a fresh invoke prepares a new one.
                        delete explorerPending[index];
                        explorerRenderIssues(body, parsed.issues);
                    }
                }).catch((error) => {
                    body.textContent = 'Execute request failed before a response: ' + error.message;
                });
                return false;
            }

            function explorerCancel(index) {
                delete explorerPending[index];
                document.querySelector('[data-explorer-form="' + index + '"]').querySelector('[data-approval]').classList.add('hidden');
                return false;
            }
        </script>
    @endif
</div>
@endsection

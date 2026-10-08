<?php

namespace LaraSlice\Generator;

use Illuminate\Support\Str;

/**
 * Generates a Flutter model, typed API client and listing/form views for a slice.
 */
class FlutterSliceGenerator
{
    protected string $flutterPath;

    public function __construct(?string $flutterPath = null)
    {
        $this->flutterPath = $flutterPath ?: config('laraslice.flutter_path', base_path('flutter_app'));
    }

    /**
     * @param  array{fields?: array<int, array{name: string, type?: string, label?: string}>, api_path?: string, force?: bool}  $options
     *                                                                                                                                    fields   - the slice's custom fields (title, description and status are always included)
     *                                                                                                                                    api_path - API route prefix without "api/", e.g. "billing/invoices" (default: plural snake name)
     *                                                                                                                                    force    - overwrite files that already exist
     */
    public function generate(string $name, array $options = []): string
    {
        // The name becomes a directory and Dart identifiers; reject anything but a plain slice name
        $studly = SliceName::canonical($name);
        $snake = Str::snake($studly);
        $apiPath = trim($options['api_path'] ?? Str::plural($snake), '/');
        if (! preg_match('#^[a-z0-9][a-z0-9/_-]*$#', $apiPath)) {
            throw new \InvalidArgumentException('The API path may only contain lowercase letters, numbers, "/", "_" and "-".');
        }

        $fields = $this->dartFields($options['fields'] ?? []);
        $targetDir = $this->flutterPath.'/lib/slices/'.$snake;

        $files = [
            "models/{$snake}_model.dart" => $this->model($studly, $fields),
            "services/{$snake}_api_service.dart" => $this->service($studly, $snake, $apiPath),
            "views/{$snake}_listing_view.dart" => $this->listingView($studly, $snake),
            "views/{$snake}_form_view.dart" => $this->formView($studly, $snake, $fields),
        ];

        if (empty($options['force'])) {
            $existing = array_filter(array_keys($files), fn ($file) => file_exists($targetDir.'/'.$file));
            if ($existing !== []) {
                throw new \RuntimeException('Flutter files already exist ('.implode(', ', $existing).'); use --force to overwrite them.');
            }
        }

        foreach ($files as $file => $contents) {
            $path = $targetDir.'/'.$file;
            if (! is_dir(dirname($path)) && ! mkdir(dirname($path), 0755, true) && ! is_dir(dirname($path))) {
                throw new \RuntimeException('Unable to create '.dirname($path));
            }
            if (file_put_contents($path, $contents, LOCK_EX) === false) {
                throw new \RuntimeException("Unable to write {$path}");
            }
        }

        return $targetDir;
    }

    /**
     * Built-in columns plus custom fields, each with its Dart type.
     *
     * @return array<int, array{name: string, camel: string, dart: string, label: string}>
     */
    protected function dartFields(array $custom): array
    {
        $fields = [
            ['name' => 'title', 'type' => 'string', 'label' => 'Title'],
            ['name' => 'description', 'type' => 'text', 'label' => 'Description'],
            ['name' => 'status', 'type' => 'string', 'label' => 'Status'],
        ];
        foreach ($custom as $field) {
            if (! is_array($field) || ! isset($field['name']) || ! preg_match('/^[a-z][a-z0-9_]{0,62}$/', $field['name'])) {
                continue;
            }
            if (! in_array($field['name'], ['id', 'title', 'description', 'status', 'created_at', 'updated_at'], true)) {
                $fields[] = $field;
            }
        }

        return array_map(fn (array $field) => [
            'name' => $field['name'],
            'camel' => Str::camel($field['name']),
            'dart' => match (strtolower($field['type'] ?? 'string')) {
                'integer', 'int', 'biginteger', 'foreign_id', 'unsignedbiginteger' => 'int',
                'decimal', 'float', 'double' => 'double',
                'boolean', 'bool' => 'bool',
                default => 'String',
            },
            'label' => $this->dartString((string) ($field['label'] ?? Str::headline($field['name']))),
        ], $fields);
    }

    /** A single-quoted Dart string literal body. */
    protected function dartString(string $value): string
    {
        return str_replace(['\\', "'", '$', "\n", "\r"], ['\\\\', "\\'", '\\$', ' ', ''], $value);
    }

    protected function model(string $studly, array $fields): string
    {
        $declarations = implode("\n", array_map(fn ($f) => "  final {$f['dart']}? {$f['camel']};", $fields));
        $params = implode("\n", array_map(fn ($f) => "    this.{$f['camel']},", $fields));
        $fromJson = implode("\n", array_map(fn ($f) => "      {$f['camel']}: ".match ($f['dart']) {
            'int' => "_toInt(json['{$f['name']}'])",
            'double' => "_toDouble(json['{$f['name']}'])",
            'bool' => "_toBool(json['{$f['name']}'])",
            default => "json['{$f['name']}']?.toString()",
        }.',', $fields));
        $toJson = implode("\n", array_map(fn ($f) => "      '{$f['name']}': {$f['camel']},", $fields));

        return <<<DART
class {$studly}Model {
  /// Null until the record has been saved.
  final int? id;
{$declarations}

  {$studly}Model({
    this.id,
{$params}
  });

  factory {$studly}Model.fromJson(Map<String, dynamic> json) {
    return {$studly}Model(
      id: _toInt(json['id']),
{$fromJson}
    );
  }

  Map<String, dynamic> toJson() {
    return {
      if (id != null) 'id': id,
{$toJson}
    };
  }

  static int? _toInt(dynamic value) => value == null ? null : int.tryParse(value.toString());
  static double? _toDouble(dynamic value) => value == null ? null : double.tryParse(value.toString());
  static bool? _toBool(dynamic value) => value == null ? null : (value == true || value == 1 || value == '1' || value == 'true');
}

DART;
    }

    protected function service(string $studly, string $snake, string $apiPath): string
    {
        return <<<DART
import 'dart:convert';
import 'package:http/http.dart' as http;
import '../models/{$snake}_model.dart';

/// Calls the slice API (Laravel Sanctum bearer token).
class {$studly}ApiService {
  final String baseUrl;
  final String? token;

  {$studly}ApiService({required this.baseUrl, this.token});

  Uri _uri(String path) => Uri.parse('\$baseUrl/api/{$apiPath}\$path');

  Map<String, String> get _headers => {
        'Accept': 'application/json',
        'Content-Type': 'application/json',
        if (token != null) 'Authorization': 'Bearer \$token',
      };

  Future<List<{$studly}Model>> getList({String? search, int page = 1, int limit = 20}) async {
    final response = await http.post(
      _uri('/list'),
      headers: _headers,
      body: jsonEncode({'search': search, 'page': page, 'limit': limit}),
    );
    _check(response, 'load the {$studly} list');
    final List items = jsonDecode(response.body)['items'] ?? [];
    return items.map((e) => {$studly}Model.fromJson(e as Map<String, dynamic>)).toList();
  }

  Future<{$studly}Model> getItemById(int id) async {
    final response = await http.get(_uri('/\$id'), headers: _headers);
    _check(response, 'load {$studly} #\$id');
    return {$studly}Model.fromJson(jsonDecode(response.body) as Map<String, dynamic>);
  }

  /// Creates the record when it has no id, otherwise updates it. Returns the record id.
  Future<int?> save({$studly}Model model) async {
    final response = await http.post(_uri('/save'), headers: _headers, body: jsonEncode(model.toJson()));
    _check(response, 'save {$studly}');
    final id = jsonDecode(response.body)['id'];
    return id == null ? null : int.tryParse(id.toString());
  }

  Future<void> delete(int id) async {
    final response = await http.delete(_uri('/\$id'), headers: _headers);
    _check(response, 'delete {$studly} #\$id');
  }

  void _check(http.Response response, String action) {
    if (response.statusCode < 200 || response.statusCode >= 300) {
      throw Exception('Could not \$action (HTTP \${response.statusCode}): \${response.body}');
    }
  }
}

DART;
    }

    protected function listingView(string $studly, string $snake): string
    {
        return <<<DART
import 'package:flutter/material.dart';
import '../models/{$snake}_model.dart';
import '../services/{$snake}_api_service.dart';
import '{$snake}_form_view.dart';

class {$studly}ListingView extends StatefulWidget {
  final {$studly}ApiService apiService;

  const {$studly}ListingView({Key? key, required this.apiService}) : super(key: key);

  @override
  State<{$studly}ListingView> createState() => _{$studly}ListingViewState();
}

class _{$studly}ListingViewState extends State<{$studly}ListingView> {
  late Future<List<{$studly}Model>> _future;

  @override
  void initState() {
    super.initState();
    _refresh();
  }

  void _refresh() {
    setState(() {
      _future = widget.apiService.getList();
    });
  }

  Future<void> _open([{$studly}Model? item]) async {
    final changed = await Navigator.push(
      context,
      MaterialPageRoute(builder: (_) => {$studly}FormView(apiService: widget.apiService, initialModel: item)),
    );
    if (changed == true) _refresh();
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(
        title: const Text('{$studly} Records'),
        actions: [IconButton(icon: const Icon(Icons.refresh), onPressed: _refresh)],
      ),
      body: FutureBuilder<List<{$studly}Model>>(
        future: _future,
        builder: (context, snapshot) {
          if (snapshot.connectionState == ConnectionState.waiting) {
            return const Center(child: CircularProgressIndicator());
          }
          if (snapshot.hasError) {
            return Center(child: Text('Error: \${snapshot.error}'));
          }
          final items = snapshot.data ?? [];
          if (items.isEmpty) {
            return const Center(child: Text('No {$studly} records found.'));
          }
          return ListView.separated(
            padding: const EdgeInsets.all(16),
            itemCount: items.length,
            separatorBuilder: (_, __) => const Divider(),
            itemBuilder: (context, index) {
              final item = items[index];
              return ListTile(
                title: Text(item.title ?? '#\${item.id}', style: const TextStyle(fontWeight: FontWeight.bold)),
                subtitle: Text('Status: \${item.status ?? '-'}'),
                trailing: const Icon(Icons.chevron_right),
                onTap: () => _open(item),
              );
            },
          );
        },
      ),
      floatingActionButton: FloatingActionButton(child: const Icon(Icons.add), onPressed: () => _open()),
    );
  }
}

DART;
    }

    protected function formView(string $studly, string $snake, array $fields): string
    {
        $textFields = array_values(array_filter($fields, fn ($f) => $f['dart'] !== 'bool'));
        $boolFields = array_values(array_filter($fields, fn ($f) => $f['dart'] === 'bool'));

        $controllers = implode("\n", array_map(fn ($f) => "  late final TextEditingController _{$f['camel']}Ctrl;", $textFields));
        $bools = implode("\n", array_map(fn ($f) => "  bool _{$f['camel']} = false;", $boolFields));
        $init = implode("\n", array_merge(
            array_map(fn ($f) => "    _{$f['camel']}Ctrl = TextEditingController(text: widget.initialModel?.{$f['camel']}?.toString() ?? '');", $textFields),
            array_map(fn ($f) => "    _{$f['camel']} = widget.initialModel?.{$f['camel']} ?? false;", $boolFields),
        ));
        $dispose = implode("\n", array_map(fn ($f) => "    _{$f['camel']}Ctrl.dispose();", $textFields));
        $build = implode("\n", array_merge(
            array_map(fn ($f) => "      {$f['camel']}: ".match ($f['dart']) {
                'int' => "int.tryParse(_{$f['camel']}Ctrl.text.trim())",
                'double' => "double.tryParse(_{$f['camel']}Ctrl.text.trim())",
                default => "_{$f['camel']}Ctrl.text.trim()",
            }.',', $textFields),
            array_map(fn ($f) => "      {$f['camel']}: _{$f['camel']},", $boolFields),
        ));
        $inputs = implode("\n", array_merge(
            array_map(fn ($f) => "                    TextFormField(\n"
                ."                      controller: _{$f['camel']}Ctrl,\n"
                ."                      decoration: const InputDecoration(labelText: '{$f['label']}', border: OutlineInputBorder()),\n"
                .($f['dart'] === 'String' ? '' : "                      keyboardType: TextInputType.number,\n")
                .($f['name'] === 'title' ? "                      validator: (val) => (val == null || val.isEmpty) ? 'Title required' : null,\n" : '')
                .($f['name'] === 'description' ? "                      maxLines: 4,\n" : '')
                ."                    ),\n                    const SizedBox(height: 16),", $textFields),
            array_map(fn ($f) => "                    SwitchListTile(\n"
                ."                      title: const Text('{$f['label']}'),\n"
                ."                      value: _{$f['camel']},\n"
                ."                      onChanged: (val) => setState(() => _{$f['camel']} = val),\n"
                .'                    ),', $boolFields),
        ));

        return <<<DART
import 'package:flutter/material.dart';
import '../models/{$snake}_model.dart';
import '../services/{$snake}_api_service.dart';

class {$studly}FormView extends StatefulWidget {
  final {$studly}ApiService apiService;
  final {$studly}Model? initialModel;

  const {$studly}FormView({Key? key, required this.apiService, this.initialModel}) : super(key: key);

  @override
  State<{$studly}FormView> createState() => _{$studly}FormViewState();
}

class _{$studly}FormViewState extends State<{$studly}FormView> {
  final _formKey = GlobalKey<FormState>();
{$controllers}
{$bools}
  bool _isLoading = false;

  @override
  void initState() {
    super.initState();
{$init}
  }

  @override
  void dispose() {
{$dispose}
    super.dispose();
  }

  Future<void> _submit() async {
    if (!_formKey.currentState!.validate()) return;

    setState(() => _isLoading = true);
    try {
      final model = {$studly}Model(
      id: widget.initialModel?.id,
{$build}
      );
      await widget.apiService.save(model);
      if (mounted) Navigator.pop(context, true);
    } catch (e) {
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text('Error: \$e')));
      }
    } finally {
      if (mounted) setState(() => _isLoading = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final isNew = widget.initialModel == null;
    return Scaffold(
      appBar: AppBar(title: Text(isNew ? 'Create {$studly}' : 'Edit {$studly}')),
      body: _isLoading
          ? const Center(child: CircularProgressIndicator())
          : Padding(
              padding: const EdgeInsets.all(16.0),
              child: Form(
                key: _formKey,
                child: ListView(
                  children: [
{$inputs}
                    const SizedBox(height: 24),
                    ElevatedButton(
                      style: ElevatedButton.styleFrom(minimumSize: const Size.fromHeight(50)),
                      onPressed: _submit,
                      child: Text(isNew ? 'Create {$studly}' : 'Save Changes'),
                    ),
                  ],
                ),
              ),
            ),
    );
  }
}

DART;
    }
}

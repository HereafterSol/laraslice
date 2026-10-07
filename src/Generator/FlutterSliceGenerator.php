<?php

namespace LaraSlice\Generator;

use Illuminate\Support\Str;

class FlutterSliceGenerator
{
    protected string $flutterPath;

    public function __construct(?string $flutterPath = null)
    {
        $this->flutterPath = $flutterPath ?: config('laraslice.flutter_path', base_path('flutter_app'));
    }

    public function generate(string $name): string
    {
        // The name becomes a directory and Dart identifiers; reject anything but a plain slice name
        SliceName::canonical($name);

        $studly = Str::studly($name);
        $camel  = Str::camel($name);
        $snake  = Str::snake($name);
        $targetDir = $this->flutterPath . '/lib/slices/' . $snake;

        foreach (['models', 'services', 'views'] as $sub) {
            $dir = $targetDir . '/' . $sub;
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
        }

        // 1. Model
        $model = <<<DART
class {$studly}Model {
  final String id;
  final String title;
  final String? description;
  final String status;

  {$studly}Model({
    required this.id,
    required this.title,
    this.description,
    required this.status,
  });

  factory {$studly}Model.fromJson(Map<String, dynamic> json) {
    return {$studly}Model(
      id: json['id'] ?? '',
      title: json['title'] ?? '',
      description: json['description'],
      status: json['status'] ?? 'draft',
    );
  }

  Map<String, dynamic> toJson() {
    return {
      'id': id,
      'title': title,
      'description': description,
      'status': status,
    };
  }
}
DART;
        file_put_contents($targetDir . "/models/{$snake}_model.dart", $model);

        // 2. Client Service
        $service = <<<DART
import 'dart:convert';
import 'package:http/http.dart' as http;
import '../models/{$snake}_model.dart';

class {$studly}ApiService {
  final String baseUrl;

  {$studly}ApiService({required this.baseUrl});

  Future<List<{$studly}Model>> getList({String? search, int page = 1, int limit = 20}) async {
    final response = await http.post(
      Uri.parse('\$baseUrl/api/{$snake}/list'),
      headers: {'Content-Type': 'application/json'},
      body: jsonEncode({
        'search': search,
        'page': page,
        'limit': limit,
      }),
    );

    if (response.statusCode == 200) {
      final data = jsonDecode(response.body);
      final List items = data['items'] ?? [];
      return items.map((e) => {$studly}Model.fromJson(e)).toList();
    } else {
      throw Exception('Failed to load {$studly} list');
    }
  }

  Future<{$studly}Model> getItemById(String id) async {
    final response = await http.get(Uri.parse('\$baseUrl/api/{$snake}/\$id'));
    if (response.statusCode == 200) {
      return {$studly}Model.fromJson(jsonDecode(response.body));
    } else {
      throw Exception('Failed to load {$studly}');
    }
  }

  Future<bool> save({$studly}Model model) async {
    final response = await http.post(
      Uri.parse('\$baseUrl/api/{$snake}/save'),
      headers: {'Content-Type': 'application/json'},
      body: jsonEncode(model.toJson()),
    );
    return response.statusCode == 200;
  }

  Future<bool> delete(String id) async {
    final response = await http.delete(Uri.parse('\$baseUrl/api/{$snake}/\$id'));
    return response.statusCode == 200;
  }
}
DART;
        file_put_contents($targetDir . "/services/{$snake}_api_service.dart", $service);

        // 3. Listing View (Flutter Widget)
        $listingView = <<<DART
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

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(
        title: const Text('{$studly} Records'),
        actions: [
          IconButton(
            icon: const Icon(Icons.refresh),
            onPressed: _refresh,
          ),
        ],
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
                title: Text(item.title, style: const TextStyle(fontWeight: FontWeight.bold)),
                subtitle: Text('Status: \${item.status}'),
                trailing: const Icon(Icons.chevron_right),
                onTap: () async {
                  final updated = await Navigator.push(
                    context,
                    MaterialPageRoute(
                      builder: (_) => {$studly}FormView(apiService: widget.apiService, initialModel: item),
                    ),
                  );
                  if (updated == true) _refresh();
                },
              );
            },
          );
        },
      ),
      floatingActionButton: FloatingActionButton(
        child: const Icon(Icons.add),
        onPressed: () async {
          final created = await Navigator.push(
            context,
            MaterialPageRoute(
              builder: (_) => {$studly}FormView(apiService: widget.apiService),
            ),
          );
          if (created == true) _refresh();
        },
      ),
    );
  }
}
DART;
        file_put_contents($targetDir . "/views/{$snake}_listing_view.dart", $listingView);

        // 4. Form View (Flutter Widget)
        $formView = <<<DART
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
  late TextEditingController _titleCtrl;
  late TextEditingController _descCtrl;
  String _status = 'draft';
  bool _isLoading = false;

  @override
  void initState() {
    super.initState();
    _titleCtrl = TextEditingController(text: widget.initialModel?.title ?? '');
    _descCtrl = TextEditingController(text: widget.initialModel?.description ?? '');
    _status = widget.initialModel?.status ?? 'draft';
  }

  @override
  void dispose() {
    _titleCtrl.dispose();
    _descCtrl.dispose();
    super.dispose();
  }

  Future<void> _submit() async {
    if (!_formKey.currentState!.validate()) return;

    setState(() => _isLoading = true);
    try {
      final model = {$studly}Model(
        id: widget.initialModel?.id ?? '',
        title: _titleCtrl.text.trim(),
        description: _descCtrl.text.trim(),
        status: _status,
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
      appBar: AppBar(
        title: Text(isNew ? 'Create {$studly}' : 'Edit {$studly}'),
      ),
      body: _isLoading
          ? const Center(child: CircularProgressIndicator())
          : Padding(
              padding: const EdgeInsets.all(16.0),
              child: Form(
                key: _formKey,
                child: Column(
                  children: [
                    TextFormField(
                      controller: _titleCtrl,
                      decoration: const InputDecoration(labelText: 'Title', border: OutlineInputBorder()),
                      validator: (val) => (val == null || val.isEmpty) ? 'Title required' : null,
                    ),
                    const SizedBox(height: 16),
                    TextFormField(
                      controller: _descCtrl,
                      decoration: const InputDecoration(labelText: 'Description', border: OutlineInputBorder()),
                      maxLines: 4,
                    ),
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
        file_put_contents($targetDir . "/views/{$snake}_form_view.dart", $formView);

        return $targetDir;
    }
}

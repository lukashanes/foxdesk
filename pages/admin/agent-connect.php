<?php
/**
 * Agent Connect — AI Connection Instructions Generator
 *
 * Generates ready-to-paste instruction packages for connecting AI tools
 * (Claude, ChatGPT, Cursor, etc.) to the helpdesk API.
 *
 * Accessed via: ?page=admin&section=agent-connect&id={agent_id}
 */

if (!is_admin()) {
    header('Location: index.php?page=dashboard');
    exit;
}

$page_title = t('Agent Connect');
$agent_id = (int) ($_GET['id'] ?? 0);

// Load agent
$agent = null;
if ($agent_id > 0) {
    $agent = db_fetch_one("SELECT * FROM users WHERE id = ? AND is_ai_agent = 1", [$agent_id]);
}

if (!$agent) {
    // No agent selected or invalid ID — show agent picker
    $all_agents = db_fetch_all("SELECT id, first_name, last_name, ai_model FROM users WHERE is_ai_agent = 1 AND is_active = 1 AND deleted_at IS NULL ORDER BY first_name");
    require_once BASE_PATH . '/includes/header.php';
    ?>
    <main class="main-content p-6">
        <div class="max-w-2xl mx-auto">
            <div class="flex items-center mb-6">
                <a href="<?php echo url('admin', ['section' => 'users', 'tab' => 'ai_agents']); ?>" class="mr-3 theme-text-muted hover:theme-text transition">
                    <i class="fas fa-arrow-left"></i>
                </a>
                <h1 class="text-2xl font-bold theme-text"><?php echo e(t('Agent Connect')); ?></h1>
            </div>

            <?php if (empty($all_agents)): ?>
            <div class="card p-12 text-center">
                <div class="w-16 h-16 rounded-full bg-blue-50 dark:bg-blue-900/20 flex items-center justify-center mx-auto mb-4">
                    <i class="fas fa-robot text-2xl text-blue-400"></i>
                </div>
                <h2 class="text-lg font-semibold theme-text mb-2"><?php echo e(t('No AI agents yet')); ?></h2>
                <p class="theme-text-muted mb-6"><?php echo e(t('Create an AI agent first, then connect it here.')); ?></p>
                <a href="<?php echo url('admin', ['section' => 'users', 'tab' => 'ai_agents']); ?>" class="btn btn-primary">
                    <i class="fas fa-plus mr-2"></i><?php echo e(t('Create AI Agent')); ?>
                </a>
            </div>
            <?php else: ?>
            <div class="card p-8">
                <div class="text-center mb-6">
                    <div class="w-16 h-16 rounded-full bg-blue-50 dark:bg-blue-900/20 flex items-center justify-center mx-auto mb-4">
                        <i class="fas fa-plug text-2xl text-blue-400"></i>
                    </div>
                    <h2 class="text-lg font-semibold theme-text mb-1"><?php echo e(t('Select an agent to connect')); ?></h2>
                    <p class="theme-text-muted text-sm"><?php echo e(t('Choose which AI agent you want to generate connection instructions for.')); ?></p>
                </div>
                <div class="space-y-2">
                    <?php foreach ($all_agents as $a): ?>
                    <a href="<?php echo url('admin', ['section' => 'agent-connect', 'id' => $a['id']]); ?>"
                       class="flex items-center justify-between p-4 rounded-lg border theme-border hover:bg-blue-50 dark:hover:bg-blue-900/10 transition group">
                        <div class="flex items-center gap-3">
                            <div class="w-10 h-10 rounded-full bg-gradient-to-br from-blue-400 to-indigo-500 flex items-center justify-center text-white font-medium">
                                <i class="fas fa-robot"></i>
                            </div>
                            <div>
                                <div class="font-medium theme-text"><?php echo e($a['first_name'] . ' ' . $a['last_name']); ?></div>
                                <?php if (!empty($a['ai_model'])): ?>
                                <div class="text-xs theme-text-muted"><?php echo e($a['ai_model']); ?></div>
                                <?php endif; ?>
                            </div>
                        </div>
                        <i class="fas fa-chevron-right theme-text-muted group-hover:text-blue-500 transition"></i>
                    </a>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </main>
    <?php
    require_once BASE_PATH . '/includes/footer.php';
    exit;
}

// Handle token generation request
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['generate_connect_token'])) {
    require_csrf_token();
    if (function_exists('generate_api_token')) {
        $token_result = generate_api_token($agent_id, $agent['first_name']);
        if ($token_result && !empty($token_result['token'])) {
            $_SESSION['agent_connect_token'] = $token_result['token'];
            $_SESSION['agent_connect_id'] = $agent_id;
            flash(t('New token generated.'), 'success');
        } else {
            flash(t('Failed to generate token.'), 'error');
        }
    }
    header('Location: index.php?page=admin&section=agent-connect&id=' . $agent_id);
    exit;
}

// Check for token availability
$token = null;
if (!empty($_SESSION['new_ai_agent_token'])) {
    $token = $_SESSION['new_ai_agent_token'];
    $_SESSION['agent_connect_token'] = $token;
    $_SESSION['agent_connect_id'] = $agent_id;
    unset($_SESSION['new_ai_agent_token']);
} elseif (!empty($_SESSION['agent_connect_token']) && ($_SESSION['agent_connect_id'] ?? 0) === $agent_id) {
    $token = $_SESSION['agent_connect_token'];
}

// Load token prefix from DB (only prefix is stored — full token is never stored)
$db_token = db_fetch_one(
    "SELECT token_prefix, created_at, last_used_at FROM api_tokens WHERE user_id = ? AND is_active = 1 ORDER BY created_at DESC LIMIT 1",
    [$agent_id]
);
$token_prefix_db = $db_token['token_prefix'] ?? null;
$token_created_at = $db_token['created_at'] ?? null;
$token_last_used  = $db_token['last_used_at'] ?? null;

// Build context data
$base_url = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http')
    . '://' . ($_SERVER['HTTP_HOST'] ?? 'your-domain.com')
    . rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');
$api_base = $base_url . '/index.php?page=api&action=';

$statuses = get_statuses();
$priorities = get_priorities();
$ticket_types = function_exists('get_ticket_types') ? get_ticket_types() : [];
$app_name = get_setting('app_name', 'FoxDesk');
$custom_instructions = get_setting('agent_custom_instructions', '');

$status_list = implode(', ', array_map(fn($s) => $s['name'], $statuses));
$priority_list = implode(', ', array_map(fn($p) => $p['name'], $priorities));
$type_list = !empty($ticket_types) ? implode(', ', array_map(fn($t) => $t['name'], $ticket_types)) : 'None configured';

$token_display = $token ?? 'YOUR_API_TOKEN_HERE';

// === Instruction templates ===

$system_prompt = "# {$app_name} — AI Agent Instructions

You are an AI assistant connected to the {$app_name} helpdesk system.
You can create tickets, add comments, update statuses, and log time via the REST API.

## Authentication

- **API Base URL:** `{$api_base}`
- **Bearer Token:** `{$token_display}`
- Include in every request: `Authorization: Bearer {$token_display}`

## Available API Endpoints

### Read Operations
- **GET** `agent-me` — Your agent info
- **GET** `agent-list-statuses` — All ticket statuses
- **GET** `agent-list-priorities` — All priority levels
- **GET** `agent-list-users` — All users (optional: `?role=agent`, `?exclude_ai=1`)
- **GET** `agent-list-tickets` — Search tickets (params: `status`, `priority`, `search`, `assignee_id`, `limit`, `offset`)
- **GET** `agent-get-ticket` — Full ticket detail (params: `hash` or `id`)

### Write Operations (POST, JSON body)
- **POST** `agent-create-ticket` — Create a ticket
  - Required: `title`
  - Optional: `description`, `priority_id`, `status_id`, `assignee_id`, `due_date`, `tags`, `duration_minutes`, `time_summary`
- **POST** `agent-add-comment` — Add comment to a ticket
  - Required: `ticket_hash` or `ticket_id`, `content`
  - Optional: `is_internal` (boolean), `duration_minutes`, `time_summary`
- **POST** `agent-update-status` — Change ticket status
  - Required: `ticket_hash` or `ticket_id`, `status_id` or `status` (name)
- **POST** `agent-log-time` — Log time entry
  - Required: `ticket_hash` or `ticket_id`, `duration_minutes`
  - Optional: `summary`, `is_billable`, `started_at`, `ended_at`

## Current System Configuration

- **Statuses:** {$status_list}
- **Priorities:** {$priority_list}
- **Ticket Types:** {$type_list}

## Usage Examples

### Create a ticket
```
POST {$api_base}agent-create-ticket
Headers: Authorization: Bearer {$token_display}, Content-Type: application/json
Body: {\"title\": \"Bug report\", \"description\": \"Details...\", \"tags\": \"bug\"}
```

### List open tickets
```
GET {$api_base}agent-list-tickets?status=Open&limit=10
Headers: Authorization: Bearer {$token_display}
```

### Add a comment
```
POST {$api_base}agent-add-comment
Headers: Authorization: Bearer {$token_display}, Content-Type: application/json
Body: {\"ticket_hash\": \"ABC123\", \"content\": \"Working on this...\", \"is_internal\": true}
```
";

if (!empty(trim($custom_instructions))) {
    $system_prompt .= "\n## Additional Instructions\n\n" . trim($custom_instructions) . "\n";
}

$claude_md = "# CLAUDE.md — {$app_name} Agent Context

## Project

{$app_name} — connected helpdesk system.
URL: {$base_url}

## API Access

API Base URL: {$api_base}

The API token is stored in `.env` file (gitignored). Load it with:
```bash
source .env
```

Environment variables:
- `HELPDESK_API_URL` — API base URL
- `HELPDESK_API_TOKEN` — Bearer token

## Quick Reference

### Create a ticket
```bash
source .env
curl -s -X POST \"\${HELPDESK_API_URL}agent-create-ticket\" \\
  -H \"Authorization: Bearer \${HELPDESK_API_TOKEN}\" \\
  -H \"Content-Type: application/json\" \\
  -d '{\"title\": \"Summary\", \"description\": \"Details...\", \"tags\": \"tag1,tag2\"}'
```

### List tickets
```bash
source .env
curl -s \"\${HELPDESK_API_URL}agent-list-tickets?status=Open&limit=10\" \\
  -H \"Authorization: Bearer \${HELPDESK_API_TOKEN}\"
```

### All Endpoints
- GET `agent-me` — Agent info
- GET `agent-list-statuses` — Statuses: {$status_list}
- GET `agent-list-priorities` — Priorities: {$priority_list}
- GET `agent-list-users` — Users (?role=agent)
- GET `agent-list-tickets` — Search (status, priority, search, limit)
- GET `agent-get-ticket` — Detail (?hash= or ?id=)
- POST `agent-create-ticket` — {title, description, priority_id, tags, duration_minutes}
- POST `agent-add-comment` — {ticket_hash, content, is_internal, duration_minutes}
- POST `agent-update-status` — {ticket_hash, status_id or status}
- POST `agent-log-time` — {ticket_hash, duration_minutes, summary}
";

if (!empty(trim($custom_instructions))) {
    $claude_md .= "\n## Instructions\n\n" . trim($custom_instructions) . "\n";
}

$env_file = "# {$app_name} API credentials for AI agents
# This file should be gitignored — never commit it
HELPDESK_API_URL={$api_base}
HELPDESK_API_TOKEN={$token_display}";

$cursor_rules = "---
description: {$app_name} helpdesk API integration
globs: \"**/*\"
---

" . $system_prompt;

$python_example = "import os
import requests
from dotenv import load_dotenv

load_dotenv()

API_URL = os.getenv('HELPDESK_API_URL')
API_TOKEN = os.getenv('HELPDESK_API_TOKEN')
HEADERS = {
    'Authorization': f'Bearer {API_TOKEN}',
    'Content-Type': 'application/json'
}

# Create a ticket
resp = requests.post(f'{API_URL}agent-create-ticket',
    headers=HEADERS,
    json={
        'title': 'Server monitoring alert',
        'description': 'CPU usage exceeded 90% for 5 minutes',
        'tags': 'monitoring,alert'
    })
print(resp.json())

# List open tickets
resp = requests.get(f'{API_URL}agent-list-tickets',
    headers=HEADERS,
    params={'status': 'Open', 'limit': 10})
for ticket in resp.json().get('tickets', []):
    print(f\"#{ticket['id']} {ticket['title']}\")

# Add a comment
resp = requests.post(f'{API_URL}agent-add-comment',
    headers=HEADERS,
    json={
        'ticket_hash': 'TICKET_HASH',
        'content': 'Investigating the issue...',
        'is_internal': True
    })";

$js_example = "import 'dotenv/config';

const API_URL = process.env.HELPDESK_API_URL;
const API_TOKEN = process.env.HELPDESK_API_TOKEN;

async function apiCall(endpoint, method = 'GET', body = null) {
    const res = await fetch(API_URL + endpoint, {
        method,
        headers: {
            'Authorization': `Bearer \${API_TOKEN}`,
            'Content-Type': 'application/json'
        },
        body: body ? JSON.stringify(body) : undefined
    });
    return res.json();
}

// Create a ticket
const ticket = await apiCall('agent-create-ticket', 'POST', {
    title: 'Server monitoring alert',
    description: 'CPU usage exceeded 90% for 5 minutes',
    tags: 'monitoring,alert'
});
console.log('Created:', ticket);

// List open tickets
const list = await apiCall('agent-list-tickets?status=Open&limit=10');
list.tickets?.forEach(t =>
    console.log(`#\${t.id} \${t.title}`)
);

// Add a comment
await apiCall('agent-add-comment', 'POST', {
    ticket_hash: 'TICKET_HASH',
    content: 'Investigating the issue...',
    is_internal: true
});";

// Handle custom instructions save
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_custom_instructions'])) {
    require_csrf_token();
    $new_instructions = trim($_POST['custom_instructions'] ?? '');
    save_setting('agent_custom_instructions', $new_instructions);
    $custom_instructions = $new_instructions;
    flash(t('Custom instructions saved.'), 'success');
    header('Location: index.php?page=admin&section=agent-connect&id=' . $agent_id);
    exit;
}

require_once BASE_PATH . '/includes/header.php';

// Helper: render code block with toolbar (buttons above, never overlapping text)
function ac_code($id, $content, $label = '', $buttons = ['copy']) {
    $h = '<div class="ac-cb rounded-lg overflow-hidden" style="border:1px solid rgba(255,255,255,0.08);">';
    $h .= '<div class="flex items-center justify-between px-3 py-1" style="background:#1e293b;">';
    $h .= '<span class="text-[11px] font-mono" style="color:#94a3b8;">' . e($label) . '</span>';
    $h .= '<div class="flex gap-1">';
    foreach ($buttons as $b) {
        if ($b === 'copy') {
            $h .= '<button onclick="copyBlock(\'' . $id . '\',this)" class="ac-tb">' . get_icon('copy','w-3 h-3 inline mr-0.5') . e(t('Copy')) . '</button>';
        } elseif (is_array($b)) {
            $h .= '<button onclick="downloadFile(\'' . $id . '\',\'' . e($b['fn']) . '\',\'' . ($b['mime'] ?? 'text/plain') . '\')" class="ac-tb">' . get_icon('download','w-3 h-3 inline mr-0.5') . e(t('Save')) . '</button>';
        }
    }
    $h .= '</div></div>';
    $h .= '<pre id="' . $id . '" class="ac-pre">' . e($content) . '</pre></div>';
    return $h;
}

$agent_name_safe = e($agent['first_name']);
$page_header_title = t('Agent Connect');
$page_header_subtitle = $agent_name_safe . ' — ' . t('Step-by-step setup for AI tools');
include BASE_PATH . '/includes/components/page-header.php';
?>

<!-- Back link -->
<div class="mb-4">
    <a href="<?php echo url('admin', ['section' => 'users', 'tab' => 'ai_agents']); ?>"
       class="text-sm text-blue-600 hover:text-blue-800 inline-flex items-center gap-1">
        <?php echo get_icon('arrow-left', 'w-4 h-4'); ?>
        <?php echo e(t('Back to AI agents')); ?>
    </a>
</div>

<p class="text-sm mb-4" style="color: var(--text-muted);">
    <?php echo e(t('Choose your AI tool below and follow the step-by-step guide to connect it to your helpdesk.')); ?>
</p>

<!-- Tool selector -->
<div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-3 mb-6">
    <button onclick="showTool('bot')" id="tool_btn_bot" class="tool-btn tool-btn-active group p-3 rounded-xl border-2 text-center transition-all cursor-pointer">
        <div class="w-10 h-10 mx-auto mb-1.5 rounded-lg bg-red-100 dark:bg-red-900/30 flex items-center justify-center">
            <?php echo get_icon('cpu', 'w-5 h-5 text-red-600'); ?>
        </div>
        <span class="text-sm font-medium block" style="color: var(--text-primary);"><?php echo e(t('Custom bot')); ?></span>
        <span class="text-[10px] block mt-0.5" style="color: var(--text-muted);">Python · JS · cURL</span>
    </button>
    <button onclick="showTool('claude_ai')" id="tool_btn_claude_ai" class="tool-btn tool-btn-inactive group p-3 rounded-xl border-2 text-center transition-all cursor-pointer">
        <div class="w-10 h-10 mx-auto mb-1.5 rounded-lg bg-purple-100 dark:bg-purple-900/30 flex items-center justify-center">
            <?php echo get_icon('message-square', 'w-5 h-5 text-purple-600'); ?>
        </div>
        <span class="text-sm font-medium block" style="color: var(--text-primary);">Claude.ai</span>
        <span class="text-[10px] block mt-0.5" style="color: var(--text-muted);"><?php echo e(t('Web chat')); ?></span>
    </button>
    <button onclick="showTool('claude_code')" id="tool_btn_claude_code" class="tool-btn tool-btn-inactive group p-3 rounded-xl border-2 text-center transition-all cursor-pointer">
        <div class="w-10 h-10 mx-auto mb-1.5 rounded-lg bg-orange-100 dark:bg-orange-900/30 flex items-center justify-center">
            <?php echo get_icon('terminal', 'w-5 h-5 text-orange-600'); ?>
        </div>
        <span class="text-sm font-medium block" style="color: var(--text-primary);">Claude Code</span>
        <span class="text-[10px] block mt-0.5" style="color: var(--text-muted);"><?php echo e(t('CLI tool')); ?></span>
    </button>
    <button onclick="showTool('chatgpt')" id="tool_btn_chatgpt" class="tool-btn tool-btn-inactive group p-3 rounded-xl border-2 text-center transition-all cursor-pointer">
        <div class="w-10 h-10 mx-auto mb-1.5 rounded-lg bg-green-100 dark:bg-green-900/30 flex items-center justify-center">
            <?php echo get_icon('message-circle', 'w-5 h-5 text-green-600'); ?>
        </div>
        <span class="text-sm font-medium block" style="color: var(--text-primary);">ChatGPT</span>
        <span class="text-[10px] block mt-0.5" style="color: var(--text-muted);"><?php echo e(t('Web chat')); ?></span>
    </button>
    <button onclick="showTool('cursor')" id="tool_btn_cursor" class="tool-btn tool-btn-inactive group p-3 rounded-xl border-2 text-center transition-all cursor-pointer">
        <div class="w-10 h-10 mx-auto mb-1.5 rounded-lg bg-blue-100 dark:bg-blue-900/30 flex items-center justify-center">
            <?php echo get_icon('edit-3', 'w-5 h-5 text-blue-600'); ?>
        </div>
        <span class="text-sm font-medium block" style="color: var(--text-primary);">Cursor</span>
        <span class="text-[10px] block mt-0.5" style="color: var(--text-muted);"><?php echo e(t('AI editor')); ?></span>
    </button>
    <button onclick="showTool('api')" id="tool_btn_api" class="tool-btn tool-btn-inactive group p-3 rounded-xl border-2 text-center transition-all cursor-pointer">
        <div class="w-10 h-10 mx-auto mb-1.5 rounded-lg bg-gray-100 dark:bg-gray-700/30 flex items-center justify-center">
            <?php echo get_icon('book-open', 'w-5 h-5 text-gray-600'); ?>
        </div>
        <span class="text-sm font-medium block" style="color: var(--text-primary);"><?php echo e(t('API Reference')); ?></span>
        <span class="text-[10px] block mt-0.5" style="color: var(--text-muted);"><?php echo e(t('Endpoints')); ?></span>
    </button>
</div>

<!-- ====================== -->
<!-- BOT PANEL (default) -->
<!-- ====================== -->
<div id="panel_bot" class="tool-panel">
<div class="card card-body">
    <div class="flex items-center gap-2 mb-5">
        <div class="w-8 h-8 rounded-lg bg-red-100 dark:bg-red-900/30 flex items-center justify-center">
            <?php echo get_icon('cpu', 'w-4 h-4 text-red-600'); ?>
        </div>
        <h3 class="font-semibold" style="color: var(--text-primary);"><?php echo t('Connect {name} to your helpdesk', ['name' => $agent_name_safe]); ?></h3>
    </div>
    <div class="space-y-6">
        <!-- Step 1: Credentials -->
        <div class="flex gap-4 items-start">
            <div class="ac-step bg-red-100 dark:bg-red-900/40 text-red-700 dark:text-red-300">1</div>
            <div class="flex-1 pt-0.5">
                <p class="font-medium text-sm" style="color: var(--text-primary);"><?php echo e(t('Save your API credentials')); ?></p>
                <?php if ($token): ?>
                    <p class="text-xs mt-0.5 mb-2" style="color: var(--text-muted);">
                        <?php echo e(t('Create a .env file in your project directory with these credentials:')); ?>
                    </p>
                    <?php echo ac_code('bot_env', $env_file, '.env', [['fn'=>'.env','mime'=>'text/plain'], 'copy']); ?>
                    <div class="mt-2 flex items-start gap-1.5 p-2 rounded-md bg-amber-50 dark:bg-amber-900/15 border border-amber-200 dark:border-amber-700">
                        <?php echo get_icon('alert-triangle', 'w-3.5 h-3.5 text-amber-600 dark:text-amber-400 mt-0.5 flex-shrink-0'); ?>
                        <p class="text-xs text-amber-700 dark:text-amber-300">
                            <?php echo e(t('Save this now — the full token is only shown once. After you leave this page, only the token prefix will be visible.')); ?>
                        </p>
                    </div>
                <?php else: ?>
                    <?php if ($token_prefix_db): ?>
                        <p class="text-xs mt-0.5 mb-3" style="color: var(--text-muted);">
                            <?php echo e(t('An active token exists for this agent (prefix: ')); ?><code class="font-mono text-xs px-1 py-0.5 rounded" style="background:var(--surface-secondary);color:var(--text-primary);"><?php echo e($token_prefix_db); ?>...</code><?php echo e(t('). The full token is no longer visible — it was only shown when it was first generated.')); ?>
                        </p>
                        <p class="text-xs mb-3" style="color: var(--text-muted);">
                            <?php echo e(t('If you have the .env file saved, continue below. If you lost the token, generate a new one.')); ?>
                        </p>
                    <?php else: ?>
                        <p class="text-xs mt-0.5 mb-3" style="color: var(--text-muted);">
                            <?php echo e(t('No API token found. Generate one to connect this agent.')); ?>
                        </p>
                    <?php endif; ?>
                    <form method="post" class="inline">
                        <?php echo csrf_field(); ?>
                        <input type="hidden" name="generate_connect_token" value="1">
                        <button type="submit"
                            class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-medium"
                            style="background: var(--primary); color: #fff;">
                            <?php echo get_icon('refresh-cw', 'w-3.5 h-3.5'); ?>
                            <?php echo e($token_prefix_db ? t('Generate new token') : t('Generate token')); ?>
                        </button>
                    </form>
                    <?php if ($token_prefix_db): ?>
                        <p class="text-xs mt-2 text-red-600 dark:text-red-400">
                            <?php echo get_icon('alert-circle', 'w-3 h-3 inline mr-0.5'); ?>
                            <?php echo e(t('Warning: generating a new token will invalidate the current one.')); ?>
                        </p>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>

        <!-- Step 2: Test -->
        <div class="flex gap-4 items-start">
            <div class="ac-step bg-red-100 dark:bg-red-900/40 text-red-700 dark:text-red-300">2</div>
            <div class="flex-1 pt-0.5">
                <p class="font-medium text-sm" style="color: var(--text-primary);"><?php echo e(t('Test the connection')); ?></p>
                <p class="text-xs mt-0.5 mb-3" style="color: var(--text-muted);">
                    <?php echo e(t('Run this command in your terminal to verify the token works:')); ?>
                </p>
                <?php echo ac_code('bot_test', "curl -s \"{$api_base}agent-me\" \\\n  -H \"Authorization: Bearer {$token_display}\"", 'curl', ['copy']); ?>
                <p class="text-xs mt-2" style="color: var(--text-muted);">
                    <?php echo e(t('Expected: JSON response with agent name and role.')); ?>
                </p>
            </div>
        </div>

        <!-- Step 3: Code examples -->
        <div class="flex gap-4 items-start">
            <div class="ac-step bg-red-100 dark:bg-red-900/40 text-red-700 dark:text-red-300">3</div>
            <div class="flex-1 pt-0.5">
                <p class="font-medium text-sm" style="color: var(--text-primary);"><?php echo e(t('Integrate with your bot')); ?></p>
                <p class="text-xs mt-0.5 mb-3" style="color: var(--text-muted);">
                    <?php echo e(t('Choose your language and use the code below as a starting point:')); ?>
                </p>

                <!-- Language sub-tabs -->
                <div class="flex gap-1 mb-3 p-0.5 rounded-lg w-fit" style="background: var(--surface-secondary);">
                    <button onclick="switchLang('python')" id="lang_btn_python" class="lang-btn lang-active px-3 py-1 rounded-md text-xs font-medium">Python</button>
                    <button onclick="switchLang('js')" id="lang_btn_js" class="lang-btn lang-inactive px-3 py-1 rounded-md text-xs font-medium">JavaScript</button>
                    <button onclick="switchLang('curl')" id="lang_btn_curl" class="lang-btn lang-inactive px-3 py-1 rounded-md text-xs font-medium">cURL</button>
                </div>

                <div id="lang_python"><?php echo ac_code('bot_python', $python_example, 'bot.py', [['fn'=>'bot.py','mime'=>'text/x-python'], 'copy']); ?></div>
                <div id="lang_js" style="display:none"><?php echo ac_code('bot_js', $js_example, 'bot.mjs', [['fn'=>'bot.mjs','mime'=>'text/javascript'], 'copy']); ?></div>
                <div id="lang_curl" style="display:none"><?php
                    $curl_examples = "# Create a ticket\ncurl -s -X POST \"{$api_base}agent-create-ticket\" \\\n  -H \"Authorization: Bearer {$token_display}\" \\\n  -H \"Content-Type: application/json\" \\\n  -d '{\"title\": \"Alert\", \"description\": \"Details\", \"tags\": \"bot\"}'\n\n# List open tickets\ncurl -s \"{$api_base}agent-list-tickets?status=Open&limit=10\" \\\n  -H \"Authorization: Bearer {$token_display}\"\n\n# Add a comment\ncurl -s -X POST \"{$api_base}agent-add-comment\" \\\n  -H \"Authorization: Bearer {$token_display}\" \\\n  -H \"Content-Type: application/json\" \\\n  -d '{\"ticket_hash\": \"HASH\", \"content\": \"Comment text\"}'";
                    echo ac_code('bot_curl', $curl_examples, 'bash', ['copy']);
                ?></div>
            </div>
        </div>

        <!-- Step 4: Done -->
        <div class="flex gap-4 items-start">
            <div class="ac-step bg-green-100 dark:bg-green-900/40 text-green-700 dark:text-green-300"><?php echo get_icon('check', 'w-4 h-4'); ?></div>
            <div class="pt-0.5">
                <p class="font-medium text-sm" style="color: var(--text-primary);"><?php echo e(t('Ready!')); ?></p>
                <p class="text-xs mt-0.5" style="color: var(--text-muted);">
                    <?php echo t('{name} can now create tickets, add comments, update statuses, and log time.', ['name' => $agent_name_safe]); ?>
                </p>
            </div>
        </div>
    </div>
</div>
<div class="mt-3 p-3 bg-orange-50 dark:bg-orange-900/10 border border-orange-200 dark:border-orange-800 rounded-lg">
    <p class="text-xs text-orange-700 dark:text-orange-300">
        <?php echo get_icon('alert-triangle', 'w-3.5 h-3.5 inline mr-1'); ?>
        <strong><?php echo e(t('Security:')); ?></strong>
        <?php echo e(t('Never commit the .env file to version control. Add it to .gitignore.')); ?>
    </p>
</div>
</div>

<!-- ====================== -->
<!-- CLAUDE.AI PANEL -->
<!-- ====================== -->
<div id="panel_claude_ai" class="tool-panel" style="display:none">
<div class="card card-body">
    <div class="flex items-center gap-2 mb-5">
        <div class="w-8 h-8 rounded-lg bg-purple-100 dark:bg-purple-900/30 flex items-center justify-center">
            <?php echo get_icon('message-square', 'w-4 h-4 text-purple-600'); ?>
        </div>
        <h3 class="font-semibold" style="color: var(--text-primary);"><?php echo e(t('Connect to Claude.ai')); ?></h3>
    </div>
    <div class="space-y-6">
        <div class="flex gap-4 items-start">
            <div class="ac-step bg-purple-100 dark:bg-purple-900/40 text-purple-700 dark:text-purple-300">1</div>
            <div class="pt-0.5">
                <p class="font-medium text-sm" style="color: var(--text-primary);"><?php echo e(t('Open claude.ai and sign in')); ?></p>
                <p class="text-xs mt-0.5" style="color: var(--text-muted);">
                    <?php echo e(t('Go to')); ?> <a href="https://claude.ai" target="_blank" class="text-blue-600 hover:underline">claude.ai</a>
                </p>
            </div>
        </div>
        <div class="flex gap-4 items-start">
            <div class="ac-step bg-purple-100 dark:bg-purple-900/40 text-purple-700 dark:text-purple-300">2</div>
            <div class="pt-0.5">
                <p class="font-medium text-sm" style="color: var(--text-primary);"><?php echo e(t('Create a new Project')); ?></p>
                <p class="text-xs mt-0.5" style="color: var(--text-muted);">
                    <?php echo t('In the sidebar, click <strong>Projects</strong> → <strong>Create Project</strong>. Name it e.g. "{app}".', ['app' => e($app_name)]); ?>
                </p>
            </div>
        </div>
        <div class="flex gap-4 items-start">
            <div class="ac-step bg-purple-100 dark:bg-purple-900/40 text-purple-700 dark:text-purple-300">3</div>
            <div class="flex-1 pt-0.5">
                <p class="font-medium text-sm" style="color: var(--text-primary);"><?php echo e(t('Paste the instructions into the project')); ?></p>
                <p class="text-xs mt-0.5 mb-3" style="color: var(--text-muted);">
                    <?php echo t('Click <strong>Set project instructions</strong>, copy the text below and paste it:'); ?>
                </p>
                <?php echo ac_code('claude_ai_prompt', $system_prompt, 'System Prompt', ['copy']); ?>
            </div>
        </div>
        <div class="flex gap-4 items-start">
            <div class="ac-step bg-green-100 dark:bg-green-900/40 text-green-700 dark:text-green-300"><?php echo get_icon('check', 'w-4 h-4'); ?></div>
            <div class="pt-0.5">
                <p class="font-medium text-sm" style="color: var(--text-primary);"><?php echo e(t('Start chatting!')); ?></p>
                <p class="text-xs mt-0.5" style="color: var(--text-muted);">
                    <?php echo e(t('Open a new conversation inside the project. Claude can now manage your helpdesk tickets.')); ?>
                </p>
            </div>
        </div>
    </div>
</div>
<div class="mt-3 p-3 bg-purple-50 dark:bg-purple-900/10 border border-purple-200 dark:border-purple-800 rounded-lg">
    <p class="text-xs text-purple-700 dark:text-purple-300">
        <?php echo get_icon('info', 'w-3.5 h-3.5 inline mr-1'); ?>
        <strong><?php echo e(t('Tip:')); ?></strong>
        <?php echo e(t('Using a Project keeps the instructions saved. You can also paste them at the beginning of any new chat.')); ?>
    </p>
</div>
</div>

<!-- ====================== -->
<!-- CLAUDE CODE PANEL -->
<!-- ====================== -->
<div id="panel_claude_code" class="tool-panel" style="display:none">
<div class="card card-body">
    <div class="flex items-center gap-2 mb-5">
        <div class="w-8 h-8 rounded-lg bg-orange-100 dark:bg-orange-900/30 flex items-center justify-center">
            <?php echo get_icon('terminal', 'w-4 h-4 text-orange-600'); ?>
        </div>
        <h3 class="font-semibold" style="color: var(--text-primary);"><?php echo e(t('Connect to Claude Code')); ?></h3>
    </div>
    <div class="space-y-6">
        <div class="flex gap-4 items-start">
            <div class="ac-step bg-orange-100 dark:bg-orange-900/40 text-orange-700 dark:text-orange-300">1</div>
            <div class="flex-1 pt-0.5">
                <p class="font-medium text-sm" style="color: var(--text-primary);"><?php echo e(t('Create a .env file in your project root')); ?></p>
                <p class="text-xs mt-0.5 mb-3" style="color: var(--text-muted);"><?php echo e(t('Save your API credentials:')); ?></p>
                <?php echo ac_code('cc_env', $env_file, '.env', [['fn'=>'.env','mime'=>'text/plain'], 'copy']); ?>
            </div>
        </div>
        <div class="flex gap-4 items-start">
            <div class="ac-step bg-orange-100 dark:bg-orange-900/40 text-orange-700 dark:text-orange-300">2</div>
            <div class="flex-1 pt-0.5">
                <p class="font-medium text-sm" style="color: var(--text-primary);"><?php echo e(t('Create a CLAUDE.md file in your project root')); ?></p>
                <p class="text-xs mt-0.5 mb-3" style="color: var(--text-muted);"><?php echo e(t('Claude Code reads this file automatically:')); ?></p>
                <?php echo ac_code('cc_md', $claude_md, 'CLAUDE.md', [['fn'=>'CLAUDE.md','mime'=>'text/markdown'], 'copy']); ?>
            </div>
        </div>
        <div class="flex gap-4 items-start">
            <div class="ac-step bg-orange-100 dark:bg-orange-900/40 text-orange-700 dark:text-orange-300">3</div>
            <div class="pt-0.5">
                <p class="font-medium text-sm" style="color: var(--text-primary);"><?php echo e(t('Add .env to .gitignore')); ?></p>
                <code class="text-xs bg-gray-100 dark:bg-gray-800 px-2 py-1 rounded mt-1 inline-block font-mono" style="color: var(--text-secondary);">echo ".env" >> .gitignore</code>
            </div>
        </div>
        <div class="flex gap-4 items-start">
            <div class="ac-step bg-green-100 dark:bg-green-900/40 text-green-700 dark:text-green-300"><?php echo get_icon('check', 'w-4 h-4'); ?></div>
            <div class="pt-0.5">
                <p class="font-medium text-sm" style="color: var(--text-primary);"><?php echo e(t('Run Claude Code')); ?></p>
                <p class="text-xs mt-0.5" style="color: var(--text-muted);">
                    <?php echo e(t('Open terminal in your project and run:')); ?> <code class="bg-gray-100 dark:bg-gray-800 px-1.5 py-0.5 rounded font-mono" style="color: var(--text-secondary);">claude</code>
                </p>
            </div>
        </div>
    </div>
</div>
</div>

<!-- ====================== -->
<!-- CHATGPT PANEL -->
<!-- ====================== -->
<div id="panel_chatgpt" class="tool-panel" style="display:none">
<div class="card card-body">
    <div class="flex items-center gap-2 mb-5">
        <div class="w-8 h-8 rounded-lg bg-green-100 dark:bg-green-900/30 flex items-center justify-center">
            <?php echo get_icon('message-circle', 'w-4 h-4 text-green-600'); ?>
        </div>
        <h3 class="font-semibold" style="color: var(--text-primary);"><?php echo e(t('Connect to ChatGPT')); ?></h3>
    </div>
    <div class="space-y-6">
        <div class="flex gap-4 items-start">
            <div class="ac-step bg-green-100 dark:bg-green-900/40 text-green-700 dark:text-green-300">1</div>
            <div class="pt-0.5">
                <p class="font-medium text-sm" style="color: var(--text-primary);"><?php echo e(t('Open ChatGPT and sign in')); ?></p>
                <p class="text-xs mt-0.5" style="color: var(--text-muted);">
                    <?php echo e(t('Go to')); ?> <a href="https://chatgpt.com" target="_blank" class="text-blue-600 hover:underline">chatgpt.com</a>
                </p>
            </div>
        </div>
        <div class="flex gap-4 items-start">
            <div class="ac-step bg-green-100 dark:bg-green-900/40 text-green-700 dark:text-green-300">2</div>
            <div class="pt-0.5">
                <p class="font-medium text-sm" style="color: var(--text-primary);"><?php echo e(t('Create a new Project')); ?></p>
                <p class="text-xs mt-0.5" style="color: var(--text-muted);">
                    <?php echo t('In the sidebar, click <strong>Projects</strong> → <strong>New Project</strong>. Name it "{app}".', ['app' => e($app_name)]); ?>
                </p>
            </div>
        </div>
        <div class="flex gap-4 items-start">
            <div class="ac-step bg-green-100 dark:bg-green-900/40 text-green-700 dark:text-green-300">3</div>
            <div class="flex-1 pt-0.5">
                <p class="font-medium text-sm" style="color: var(--text-primary);"><?php echo e(t('Set project instructions')); ?></p>
                <p class="text-xs mt-0.5 mb-3" style="color: var(--text-muted);">
                    <?php echo t('Click the <strong>pencil icon</strong> next to "Instructions" and paste:'); ?>
                </p>
                <?php echo ac_code('chatgpt_prompt', $system_prompt, 'System Prompt', ['copy']); ?>
            </div>
        </div>
        <div class="flex gap-4 items-start">
            <div class="ac-step bg-green-100 dark:bg-green-900/40 text-green-700 dark:text-green-300"><?php echo get_icon('check', 'w-4 h-4'); ?></div>
            <div class="pt-0.5">
                <p class="font-medium text-sm" style="color: var(--text-primary);"><?php echo e(t('Start chatting!')); ?></p>
                <p class="text-xs mt-0.5" style="color: var(--text-muted);"><?php echo e(t('Open a conversation inside the project. ChatGPT can now manage your tickets.')); ?></p>
            </div>
        </div>
    </div>
</div>
</div>

<!-- ====================== -->
<!-- CURSOR PANEL -->
<!-- ====================== -->
<div id="panel_cursor" class="tool-panel" style="display:none">
<div class="card card-body">
    <div class="flex items-center gap-2 mb-5">
        <div class="w-8 h-8 rounded-lg bg-blue-100 dark:bg-blue-900/30 flex items-center justify-center">
            <?php echo get_icon('edit-3', 'w-4 h-4 text-blue-600'); ?>
        </div>
        <h3 class="font-semibold" style="color: var(--text-primary);"><?php echo e(t('Connect to Cursor')); ?></h3>
    </div>
    <div class="space-y-6">
        <div class="flex gap-4 items-start">
            <div class="ac-step bg-blue-100 dark:bg-blue-900/40 text-blue-700 dark:text-blue-300">1</div>
            <div class="flex-1 pt-0.5">
                <p class="font-medium text-sm" style="color: var(--text-primary);"><?php echo e(t('Create a .env file in your project root')); ?></p>
                <p class="text-xs mt-0.5 mb-3" style="color: var(--text-muted);"><?php echo e(t('Save your API credentials:')); ?></p>
                <?php echo ac_code('cursor_env', $env_file, '.env', [['fn'=>'.env','mime'=>'text/plain'], 'copy']); ?>
            </div>
        </div>
        <div class="flex gap-4 items-start">
            <div class="ac-step bg-blue-100 dark:bg-blue-900/40 text-blue-700 dark:text-blue-300">2</div>
            <div class="flex-1 pt-0.5">
                <p class="font-medium text-sm" style="color: var(--text-primary);"><?php echo e(t('Create a Cursor rules file')); ?></p>
                <p class="text-xs mt-0.5 mb-3" style="color: var(--text-muted);">
                    <?php echo t('Create <code>.cursor/rules/</code> folder, then save as <code>.cursor/rules/helpdesk.mdc</code>:'); ?>
                </p>
                <?php echo ac_code('cursor_rules', $cursor_rules, 'helpdesk.mdc', [['fn'=>'helpdesk.mdc','mime'=>'text/markdown'], 'copy']); ?>
            </div>
        </div>
        <div class="flex gap-4 items-start">
            <div class="ac-step bg-blue-100 dark:bg-blue-900/40 text-blue-700 dark:text-blue-300">3</div>
            <div class="pt-0.5">
                <p class="font-medium text-sm" style="color: var(--text-primary);"><?php echo e(t('Add .env to .gitignore')); ?></p>
            </div>
        </div>
        <div class="flex gap-4 items-start">
            <div class="ac-step bg-green-100 dark:bg-green-900/40 text-green-700 dark:text-green-300"><?php echo get_icon('check', 'w-4 h-4'); ?></div>
            <div class="pt-0.5">
                <p class="font-medium text-sm" style="color: var(--text-primary);"><?php echo e(t('Open your project in Cursor')); ?></p>
                <p class="text-xs mt-0.5" style="color: var(--text-muted);"><?php echo e(t('Cursor reads the rules file automatically.')); ?></p>
            </div>
        </div>
    </div>
</div>
</div>

<!-- ====================== -->
<!-- API REFERENCE PANEL -->
<!-- ====================== -->
<div id="panel_api" class="tool-panel" style="display:none">
<div class="card card-body">
    <div class="flex items-center gap-2 mb-5">
        <div class="w-8 h-8 rounded-lg bg-gray-100 dark:bg-gray-700/30 flex items-center justify-center">
            <?php echo get_icon('book-open', 'w-4 h-4 text-gray-600'); ?>
        </div>
        <h3 class="font-semibold" style="color: var(--text-primary);"><?php echo e(t('API Reference')); ?></h3>
    </div>

    <div class="overflow-x-auto">
        <table class="w-full text-xs">
            <thead>
                <tr style="border-bottom: 1px solid var(--border-color);">
                    <th class="text-left py-2 pr-3 font-semibold" style="color: var(--text-secondary);"><?php echo e(t('Method')); ?></th>
                    <th class="text-left py-2 pr-3 font-semibold" style="color: var(--text-secondary);"><?php echo e(t('Endpoint')); ?></th>
                    <th class="text-left py-2 font-semibold" style="color: var(--text-secondary);"><?php echo e(t('Description')); ?></th>
                </tr>
            </thead>
            <tbody class="font-mono" style="color: var(--text-primary);">
                <?php
                $endpoints = [
                    ['GET', 'agent-me', t('Agent info')],
                    ['GET', 'agent-list-statuses', t('All ticket statuses')],
                    ['GET', 'agent-list-priorities', t('All priority levels')],
                    ['GET', 'agent-list-users', t('All users') . ' (?role=agent)'],
                    ['GET', 'agent-list-tickets', t('Search tickets') . ' (status, priority, search, limit)'],
                    ['GET', 'agent-get-ticket', t('Ticket detail') . ' (?hash= or ?id=)'],
                    ['POST', 'agent-create-ticket', t('Create ticket') . ' (title*, description, priority_id, tags)'],
                    ['POST', 'agent-add-comment', t('Add comment') . ' (ticket_hash*, content*, is_internal)'],
                    ['POST', 'agent-update-status', t('Change status') . ' (ticket_hash*, status_id or status)'],
                    ['POST', 'agent-log-time', t('Log time') . ' (ticket_hash*, duration_minutes*, summary)'],
                ];
                foreach ($endpoints as $ep):
                    $color = $ep[0] === 'GET' ? 'blue' : 'green';
                ?>
                <tr style="border-bottom: 1px solid var(--border-color);">
                    <td class="py-1.5 pr-3"><span class="bg-<?php echo $color; ?>-100 text-<?php echo $color; ?>-700 dark:bg-<?php echo $color; ?>-900/30 dark:text-<?php echo $color; ?>-300 px-1.5 py-0.5 rounded text-[10px] font-bold"><?php echo $ep[0]; ?></span></td>
                    <td class="py-1.5 pr-3"><?php echo e($ep[1]); ?></td>
                    <td class="py-1.5 font-sans"><?php echo e($ep[2]); ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <div class="mt-5">
        <h4 class="text-xs font-semibold uppercase tracking-wide mb-3" style="color: var(--text-muted);"><?php echo e(t('System Configuration')); ?></h4>
        <div class="grid grid-cols-1 sm:grid-cols-3 gap-3 text-xs">
            <div class="p-2 rounded-lg" style="background: var(--surface-secondary);">
                <strong style="color: var(--text-secondary);"><?php echo e(t('Statuses')); ?></strong>
                <p class="mt-0.5" style="color: var(--text-muted);"><?php echo e($status_list); ?></p>
            </div>
            <div class="p-2 rounded-lg" style="background: var(--surface-secondary);">
                <strong style="color: var(--text-secondary);"><?php echo e(t('Priorities')); ?></strong>
                <p class="mt-0.5" style="color: var(--text-muted);"><?php echo e($priority_list); ?></p>
            </div>
            <div class="p-2 rounded-lg" style="background: var(--surface-secondary);">
                <strong style="color: var(--text-secondary);"><?php echo e(t('Ticket Types')); ?></strong>
                <p class="mt-0.5" style="color: var(--text-muted);"><?php echo e($type_list); ?></p>
            </div>
        </div>
    </div>

    <div class="mt-5">
        <h4 class="text-xs font-semibold uppercase tracking-wide mb-3" style="color: var(--text-muted);"><?php echo e(t('Full System Prompt')); ?></h4>
        <p class="text-xs mb-2" style="color: var(--text-muted);"><?php echo e(t('For AI tools not listed above, paste this into the tool\'s instruction field:')); ?></p>
        <?php echo ac_code('api_prompt', $system_prompt, 'System Prompt', ['copy']); ?>
    </div>
</div>
</div>


<!-- Custom Instructions (collapsible) -->
<div class="card card-body mt-6">
    <details>
        <summary class="cursor-pointer text-xs font-semibold uppercase tracking-wide select-none" style="color: var(--text-muted);">
            <?php echo get_icon('settings', 'w-4 h-4 inline mr-1'); ?>
            <?php echo e(t('Custom agent instructions')); ?>
        </summary>
        <div class="mt-3">
            <p class="text-xs mb-2" style="color: var(--text-muted);">
                <?php echo e(t('Add custom behavioral instructions included in all generated packages.')); ?>
            </p>
            <form method="post" class="space-y-3">
                <?php echo csrf_field(); ?>
                <textarea name="custom_instructions" rows="4" class="form-textarea w-full text-sm font-mono"
                    placeholder="<?php echo e(t('Example: Always use Czech language for comments. Tag bugs with "bug".')); ?>"
                ><?php echo e($custom_instructions); ?></textarea>
                <button type="submit" name="save_custom_instructions" class="btn btn-primary btn-sm">
                    <?php echo e(t('Save instructions')); ?>
                </button>
            </form>
        </div>
    </details>
</div>


<style>
.ac-step { flex-shrink:0; width:1.75rem; height:1.75rem; border-radius:9999px; display:flex; align-items:center; justify-content:center; font-size:0.75rem; font-weight:700; }
.ac-pre { padding:0.75rem; font-size:0.75rem; font-family:ui-monospace,monospace; overflow-x:auto; white-space:pre-wrap; margin:0; background:#0f172a; color:#4ade80; max-height:280px; overflow-y:auto; }
.ac-tb { font-size:0.6875rem; padding:0.125rem 0.5rem; border-radius:0.25rem; color:#cbd5e1; transition:all 0.15s; display:flex; align-items:center; gap:0.125rem; cursor:pointer; background:transparent; border:none; }
.ac-tb:hover { color:#fff; background:rgba(255,255,255,0.1); }
.tool-btn-active { border-color: var(--accent-color, #6366f1) !important; background: var(--surface-primary); box-shadow: 0 1px 3px rgba(0,0,0,0.1); }
.tool-btn-inactive { border-color: transparent; background: var(--surface-secondary); }
.tool-btn-inactive:hover { border-color: var(--border-color); background: var(--surface-primary); }
.lang-active { background: var(--surface-primary); box-shadow: 0 1px 2px rgba(0,0,0,0.08); color: var(--text-primary); }
.lang-inactive { color: var(--text-muted); }
.lang-inactive:hover { color: var(--text-secondary); }
</style>

<script>
var toolNames = ['bot','claude_ai','claude_code','chatgpt','cursor','api'];
function showTool(id) {
    toolNames.forEach(function(n) {
        var p = document.getElementById('panel_' + n);
        var b = document.getElementById('tool_btn_' + n);
        if (p) p.style.display = 'none';
        if (b) { b.classList.remove('tool-btn-active'); b.classList.add('tool-btn-inactive'); }
    });
    var ap = document.getElementById('panel_' + id);
    var ab = document.getElementById('tool_btn_' + id);
    if (ap) ap.style.display = '';
    if (ab) { ab.classList.remove('tool-btn-inactive'); ab.classList.add('tool-btn-active'); }
}

var langNames = ['python','js','curl'];
function switchLang(id) {
    langNames.forEach(function(n) {
        var p = document.getElementById('lang_' + n);
        var b = document.getElementById('lang_btn_' + n);
        if (p) p.style.display = 'none';
        if (b) { b.classList.remove('lang-active'); b.classList.add('lang-inactive'); }
    });
    var ap = document.getElementById('lang_' + id);
    var ab = document.getElementById('lang_btn_' + id);
    if (ap) ap.style.display = '';
    if (ab) { ab.classList.remove('lang-inactive'); ab.classList.add('lang-active'); }
}

function copyBlock(id, btn) {
    var el = document.getElementById(id);
    if (!el) return;
    var text = el.textContent || el.innerText;
    if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(text).then(function() { showDone(btn); }).catch(function() { fbCopy(text, btn); });
    } else { fbCopy(text, btn); }
}
function fbCopy(text, btn) {
    var ta = document.createElement('textarea'); ta.value = text; ta.style.position='fixed'; ta.style.left='-9999px';
    document.body.appendChild(ta); ta.select();
    try { document.execCommand('copy'); showDone(btn); } catch(e) { alert('Copy failed'); }
    document.body.removeChild(ta);
}
function showDone(btn) {
    if (!btn) return;
    var o = btn.innerHTML; btn.innerHTML = '\u2713 <?php echo e(t('Copied!')); ?>'; btn.style.color = '#4ade80';
    setTimeout(function() { btn.innerHTML = o; btn.style.color = ''; }, 2000);
}
function downloadFile(id, fn, mime) {
    var el = document.getElementById(id); if (!el) return;
    var text = el.textContent || el.innerText;
    var blob = new Blob([text], {type: mime || 'text/plain'});
    var url = URL.createObjectURL(blob);
    var a = document.createElement('a'); a.href = url; a.download = fn;
    document.body.appendChild(a); a.click(); document.body.removeChild(a);
    URL.revokeObjectURL(url);
}
</script>

<?php require_once BASE_PATH . '/includes/footer.php'; ?>

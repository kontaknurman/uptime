<?php
require_once 'bootstrap.php';

$message = '';
$errors = [];

// Handle category actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'create') {
        // Create new category
        $name = Auth::sanitizeInput($_POST['name'] ?? '');
        $color = $_POST['color'] ?? '#6B7280';
        $icon = Auth::sanitizeInput($_POST['icon'] ?? 'folder');
        $description = Auth::sanitizeInput($_POST['description'] ?? '');
        
        if (empty($name)) {
            $errors[] = 'Category name is required';
        } else if (strlen($name) > 50) {
            $errors[] = 'Category name must be less than 50 characters';
        }
        
        if (!preg_match('/^#[0-9A-F]{6}$/i', $color)) {
            $color = '#6B7280'; // Default gray if invalid color
        }
        
        if (empty($errors)) {
            try {
                // Check if category name already exists
                $existing = $db->fetchOne('SELECT id FROM categories WHERE name = ?', [$name]);
                if ($existing) {
                    $errors[] = 'Category with this name already exists';
                } else {
                    $categoryId = $db->insert('categories', [
                        'name' => $name,
                        'color' => $color,
                        'icon' => $icon,
                        'description' => $description
                    ]);
                    $message = '<div class="bg-green-50 border border-green-200 text-green-600 px-4 py-3 rounded mb-4">Category "' . htmlspecialchars($name) . '" created successfully!</div>';
                }
            } catch (Exception $e) {
                $errors[] = 'Failed to create category: ' . $e->getMessage();
            }
        }
        
    } elseif ($action === 'update') {
        // Update existing category
        $categoryId = (int)($_POST['category_id'] ?? 0);
        $name = Auth::sanitizeInput($_POST['name'] ?? '');
        $color = $_POST['color'] ?? '#6B7280';
        $icon = Auth::sanitizeInput($_POST['icon'] ?? 'folder');
        $description = Auth::sanitizeInput($_POST['description'] ?? '');
        
        if (empty($name)) {
            $errors[] = 'Category name is required';
        }
        
        if (!preg_match('/^#[0-9A-F]{6}$/i', $color)) {
            $color = '#6B7280';
        }
        
        if (empty($errors) && $categoryId > 0) {
            try {
                // Check if new name conflicts with another category
                $existing = $db->fetchOne('SELECT id FROM categories WHERE name = ? AND id != ?', [$name, $categoryId]);
                if ($existing) {
                    $errors[] = 'Another category with this name already exists';
                } else {
                    $updated = $db->update('categories', [
                        'name' => $name,
                        'color' => $color,
                        'icon' => $icon,
                        'description' => $description
                    ], 'id = ?', [$categoryId]);
                    
                    if ($updated > 0) {
                        $message = '<div class="bg-green-50 border border-green-200 text-green-600 px-4 py-3 rounded mb-4">Category updated successfully!</div>';
                    }
                }
            } catch (Exception $e) {
                $errors[] = 'Failed to update category: ' . $e->getMessage();
            }
        }
        
    } elseif ($action === 'delete') {
        // Delete category
        $categoryId = (int)($_POST['category_id'] ?? 0);
        
        if ($categoryId > 0) {
            try {
                // Check if category has checks
                $checkCount = $db->fetchColumn('SELECT COUNT(*) FROM checks WHERE category_id = ?', [$categoryId]);
                
                if ($checkCount > 0) {
                    $errors[] = "Cannot delete category: {$checkCount} check(s) are using this category. Please reassign them first.";
                } else {
                    $deleted = $db->delete('categories', 'id = ?', [$categoryId]);
                    if ($deleted > 0) {
                        $message = '<div class="bg-green-50 border border-green-200 text-green-600 px-4 py-3 rounded mb-4">Category deleted successfully!</div>';
                    }
                }
            } catch (Exception $e) {
                $errors[] = 'Failed to delete category: ' . $e->getMessage();
            }
        }
    }
}

// Get all categories with statistics
$categories = [];
try {
    $categories = $db->fetchAll("
        SELECT c.*, 
               COUNT(DISTINCT ch.id) as total_checks,
               SUM(CASE WHEN ch.enabled = 1 THEN 1 ELSE 0 END) as active_checks,
               SUM(CASE WHEN ch.last_state = 'DOWN' AND ch.enabled = 1 THEN 1 ELSE 0 END) as down_checks
        FROM categories c
        LEFT JOIN checks ch ON c.id = ch.category_id
        GROUP BY c.id
        ORDER BY c.name ASC
    ");
} catch (Exception $e) {
    $errors[] = 'Failed to load categories: ' . $e->getMessage();
}

// Predefined colors for easy selection
$colorOptions = [
    '#10B981' => 'Green',
    '#3B82F6' => 'Blue',
    '#8B5CF6' => 'Purple',
    '#EC4899' => 'Pink',
    '#EF4444' => 'Red',
    '#F59E0B' => 'Orange',
    '#14B8A6' => 'Teal',
    '#6B7280' => 'Gray',
    '#F97316' => 'Dark Orange',
    '#06B6D4' => 'Cyan',
    '#84CC16' => 'Lime',
    '#A855F7' => 'Violet'
];

$content = '
<div class="space-y-6">
    <div class="flex justify-between items-center">
        <h1 class="text-2xl font-bold text-gray-900">Category Management</h1>
        <button onclick="openCreateModal()" 
                class="bg-indigo-600 text-white px-4 py-2 rounded-md hover:bg-indigo-700">
            Add New Category
        </button>
    </div>

    ' . $message;

if (!empty($errors)) {
    $content .= '
    <div class="bg-red-50 border border-red-200 text-red-600 px-4 py-3 rounded">
        <ul class="list-disc list-inside space-y-1">' . 
            implode('', array_map(fn($e) => '<li>' . htmlspecialchars($e) . '</li>', $errors)) . 
        '</ul>
    </div>';
}

$content .= '
    <!-- Categories List -->
    <div class="bg-white shadow rounded-lg">
        <div class="px-6 py-4 border-b border-gray-200">
            <h2 class="text-lg font-medium text-gray-900">Categories (' . count($categories) . ')</h2>
        </div>
        
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Color</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Name</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Description</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Total Checks</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Active</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Down</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Actions</th>
                    </tr>
                </thead>
                <tbody class="bg-white divide-y divide-gray-200">';

if (empty($categories)) {
    $content .= '
                    <tr>
                        <td colspan="7" class="px-6 py-8 text-center text-gray-500">
                            No categories found. Click "Add New Category" to create your first category.
                        </td>
                    </tr>';
} else {
    foreach ($categories as $category) {
        $content .= '
                    <tr>
                        <td class="px-6 py-4">
                            <div class="w-8 h-8 rounded-full flex items-center justify-center" 
                                 style="background-color: ' . $category['color'] . '20;">
                                <div class="w-4 h-4 rounded-full" style="background-color: ' . $category['color'] . ';"></div>
                            </div>
                        </td>
                        <td class="px-6 py-4">
                            <div class="text-sm font-medium text-gray-900">' . htmlspecialchars($category['name']) . '</div>
                        </td>
                        <td class="px-6 py-4">
                            <div class="text-sm text-gray-600">' . 
                            (empty($category['description']) ? '<span class="text-gray-400">-</span>' : htmlspecialchars($category['description'])) . '</div>
                        </td>
                        <td class="px-6 py-4 text-sm text-gray-900">' . $category['total_checks'] . '</td>
                        <td class="px-6 py-4 text-sm text-gray-900">' . ($category['active_checks'] ?? 0) . '</td>
                        <td class="px-6 py-4">
                            ' . ($category['down_checks'] > 0 
                                ? '<span class="badge bg-red-100 text-red-800">' . $category['down_checks'] . '</span>' 
                                : '<span class="text-gray-400">0</span>') . '
                        </td>
                        <td class="px-6 py-4 text-sm space-x-2">
                            <button onclick="openEditModal(' . htmlspecialchars(json_encode($category)) . ')" 
                                    class="text-indigo-600 hover:text-indigo-900">Edit</button>
                            <button onclick="deleteCategory(' . $category['id'] . ', \'' . htmlspecialchars($category['name'], ENT_QUOTES) . '\', ' . $category['total_checks'] . ')" 
                                    class="text-red-600 hover:text-red-900">Delete</button>
                        </td>
                    </tr>';
    }
}

$content .= '
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Create/Edit Modal -->
<div id="categoryModal" class="hidden fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full z-50">
    <div class="relative top-20 mx-auto p-5 border w-96 shadow-lg rounded-md bg-white">
        <h3 id="modalTitle" class="text-lg font-bold text-gray-900 mb-4">Add New Category</h3>
        
        <form id="categoryForm" method="POST">
            <input type="hidden" name="csrf_token" value="' . $auth->getCsrfToken() . '">
            <input type="hidden" name="action" id="formAction" value="create">
            <input type="hidden" name="category_id" id="categoryId" value="">
            
            <div class="space-y-4">
                <div>
                    <label for="name" class="block text-sm font-medium text-gray-700">Category Name</label>
                    <input type="text" id="name" name="name" required maxlength="50"
                           class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-indigo-500 focus:border-indigo-500">
                </div>
                
                <div>
                    <label for="color" class="block text-sm font-medium text-gray-700">Color</label>
                    <div class="mt-1 flex items-center space-x-2">
                        <input type="color" id="color" name="color" value="#6B7280"
                               class="h-10 w-20 border border-gray-300 rounded cursor-pointer">
                        <select id="colorPreset" onchange="document.getElementById(\'color\').value = this.value"
                                class="flex-1 px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-indigo-500 focus:border-indigo-500">
                            <option value="">Select preset color...</option>';

foreach ($colorOptions as $hex => $name) {
    $content .= '<option value="' . $hex . '" style="color: ' . $hex . ';">● ' . $name . '</option>';
}

$content .= '
                        </select>
                    </div>
                </div>
                
                <div>
                    <label for="icon" class="block text-sm font-medium text-gray-700">Icon Name (optional)</label>
                    <input type="text" id="icon" name="icon" placeholder="e.g., folder, server, database"
                           class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-indigo-500 focus:border-indigo-500">
                </div>
                
                <div>
                    <label for="description" class="block text-sm font-medium text-gray-700">Description (optional)</label>
                    <textarea id="description" name="description" rows="3"
                              class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-indigo-500 focus:border-indigo-500"
                              placeholder="Brief description of this category"></textarea>
                </div>
            </div>
            
            <div class="mt-6 flex justify-end space-x-3">
                <button type="button" onclick="closeModal()" 
                        class="px-4 py-2 border border-gray-300 rounded-md text-gray-700 hover:bg-gray-50">
                    Cancel
                </button>
                <button type="submit" 
                        class="px-4 py-2 bg-indigo-600 text-white rounded-md hover:bg-indigo-700">
                    <span id="submitText">Create Category</span>
                </button>
            </div>
        </form>
    </div>
</div>

<script>
function openCreateModal() {
    document.getElementById("modalTitle").textContent = "Add New Category";
    document.getElementById("formAction").value = "create";
    document.getElementById("categoryId").value = "";
    document.getElementById("name").value = "";
    document.getElementById("color").value = "#6B7280";
    document.getElementById("colorPreset").value = "";
    document.getElementById("icon").value = "";
    document.getElementById("description").value = "";
    document.getElementById("submitText").textContent = "Create Category";
    document.getElementById("categoryModal").classList.remove("hidden");
}

function openEditModal(category) {
    document.getElementById("modalTitle").textContent = "Edit Category";
    document.getElementById("formAction").value = "update";
    document.getElementById("categoryId").value = category.id;
    document.getElementById("name").value = category.name;
    document.getElementById("color").value = category.color;
    document.getElementById("colorPreset").value = category.color;
    document.getElementById("icon").value = category.icon || "";
    document.getElementById("description").value = category.description || "";
    document.getElementById("submitText").textContent = "Update Category";
    document.getElementById("categoryModal").classList.remove("hidden");
}

function closeModal() {
    document.getElementById("categoryModal").classList.add("hidden");
}

function deleteCategory(id, name, checkCount) {
    if (checkCount > 0) {
        alert(`Cannot delete "${name}": ${checkCount} check(s) are using this category. Please reassign them first.`);
        return;
    }
    
    if (confirm(`Are you sure you want to delete category "${name}"?`)) {
        const form = document.createElement("form");
        form.method = "POST";
        form.innerHTML = `
            <input type="hidden" name="csrf_token" value="' . $auth->getCsrfToken() . '">
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="category_id" value="${id}">
        `;
        document.body.appendChild(form);
        form.submit();
    }
}

// Close modal when clicking outside
document.getElementById("categoryModal").addEventListener("click", function(e) {
    if (e.target === this) {
        closeModal();
    }
});

// Visual feedback for color selection
document.getElementById("color").addEventListener("change", function() {
    document.getElementById("colorPreset").value = this.value;
});
</script>';

renderTemplate('Category Management', $content);
<?php
/**
 * Categories Management Page - Modern UI
 */

require_once 'bootstrap.php';

$auth->requireAuth();

$message = '';
$errors = [];

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    switch ($action) {
        case 'create':
        case 'update':
            $name = trim($_POST['name'] ?? '');
            $color = $_POST['color'] ?? '#6B7280';
            $icon = $_POST['icon'] ?? 'folder';
            $description = trim($_POST['description'] ?? '');
            $categoryId = $action === 'update' ? (int)($_POST['category_id'] ?? 0) : 0;
            
            // Validation
            if (empty($name)) {
                $errors[] = 'Category name is required';
            } elseif (strlen($name) > 50) {
                $errors[] = 'Category name must be less than 50 characters';
            } else {
                if (!preg_match('/^#[0-9A-F]{6}$/i', $color)) {
                    $color = '#6B7280';
                }
                
                try {
                    // Check for duplicate names
                    $existingQuery = $action === 'update' ? 
                        "SELECT id FROM categories WHERE name = ? AND id != ?" : 
                        "SELECT id FROM categories WHERE name = ?";
                    $existingParams = $action === 'update' ? [$name, $categoryId] : [$name];
                    
                    $existing = $db->fetchOne($existingQuery, $existingParams);
                    if ($existing) {
                        $errors[] = 'A category with this name already exists';
                    } else {
                        if ($action === 'create') {
                            $db->insert('categories', [
                                'name' => $name,
                                'color' => $color,
                                'icon' => $icon,
                                'description' => $description
                            ]);
                            $message = '<div class="mb-4 p-4 bg-green-50 border-l-4 border-green-500 rounded-lg">
                                <p class="text-green-800 font-medium">Category created successfully!</p>
                            </div>';
                        } else {
                            $db->update('categories', [
                                'name' => $name,
                                'color' => $color,
                                'icon' => $icon,
                                'description' => $description
                            ], 'id = ?', [$categoryId]);
                            $message = '<div class="mb-4 p-4 bg-blue-50 border-l-4 border-blue-500 rounded-lg">
                                <p class="text-blue-800 font-medium">Category updated successfully!</p>
                            </div>';
                        }
                    }
                } catch (Exception $e) {
                    $errors[] = 'Database error: ' . $e->getMessage();
                }
            }
            break;
            
        case 'delete':
            $categoryId = (int)($_POST['category_id'] ?? 0);
            if ($categoryId) {
                try {
                    // Check if category has checks
                    $checkCount = $db->fetchColumn(
                        "SELECT COUNT(*) FROM checks WHERE category_id = ?", 
                        [$categoryId]
                    );
                    
                    if ($checkCount > 0) {
                        $errors[] = "Cannot delete category with $checkCount assigned checks. Please reassign them first.";
                    } else {
                        $deleted = $db->delete('categories', 'id = ?', [$categoryId]);
                        if ($deleted > 0) {
                            $message = '<div class="mb-4 p-4 bg-green-50 border-l-4 border-green-500 rounded-lg">
                                <p class="text-green-800 font-medium">Category deleted successfully!</p>
                            </div>';
                        }
                    }
                } catch (Exception $e) {
                    $errors[] = 'Failed to delete category: ' . $e->getMessage();
                }
            }
            break;
    }
}

// Get all categories with statistics
$categories = [];
try {
    $categories = $db->fetchAll("
        SELECT c.*, 
               COUNT(DISTINCT ch.id) as total_checks,
               SUM(CASE WHEN ch.enabled = 1 THEN 1 ELSE 0 END) as active_checks,
               SUM(CASE WHEN ch.last_state = 'DOWN' AND ch.enabled = 1 THEN 1 ELSE 0 END) as down_checks,
               SUM(CASE WHEN ch.last_state = 'UP' AND ch.enabled = 1 THEN 1 ELSE 0 END) as up_checks
        FROM categories c
        LEFT JOIN checks ch ON c.id = ch.category_id
        GROUP BY c.id
        ORDER BY c.name ASC
    ");
} catch (Exception $e) {
    $errors[] = 'Failed to load categories: ' . $e->getMessage();
}

// Predefined colors and icons
$colorOptions = [
    '#10B981' => 'Green',
    '#3B82F6' => 'Blue', 
    '#8B5CF6' => 'Purple',
    '#EC4899' => 'Pink',
    '#EF4444' => 'Red',
    '#F59E0B' => 'Orange',
    '#14B8A6' => 'Teal',
    '#6B7280' => 'Gray'
];

$iconOptions = [
    'folder' => 'Folder',
    'api' => 'API',
    'globe' => 'Website',
    'database' => 'Database',
    'external-link' => 'External Link',
    'activity' => 'Monitoring'
];

ob_start();
?>

<style>
    .card-hover {
        transition: all 0.3s ease;
        border: 1px solid #e5e7eb;
    }
    .card-hover:hover {
        transform: translateY(-2px);
        box-shadow: 0 8px 16px rgba(0,0,0,0.1);
        border-color: #6b7280;
    }
    .color-option {
        width: 40px;
        height: 40px;
        border-radius: 8px;
        cursor: pointer;
        border: 2px solid #e5e7eb;
        transition: all 0.2s;
    }
    .color-option:hover {
        transform: scale(1.1);
    }
    .color-option.selected {
        border: 3px solid #374151;
        transform: scale(1.1);
    }
    .gradient-bg {
        background: linear-gradient(135deg, #6b7280 0%, #9ca3af 100%);
    }
</style>

<div class="space-y-6">
    <?php echo $message; ?>
    
    <?php if (!empty($errors)): ?>
    <div class="mb-4 p-4 bg-red-50 border-l-4 border-red-500 rounded-lg">
        <div class="flex">
            <svg class="w-5 h-5 text-red-600 mr-2" fill="currentColor" viewBox="0 0 20 20">
                <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z" clip-rule="evenodd"/>
            </svg>
            <div>
                <?php foreach ($errors as $error): ?>
                    <p class="text-red-800 font-medium"><?php echo htmlspecialchars($error); ?></p>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
    <?php endif; ?>
    
    <!-- Header -->
    <div class="bg-gradient-to-r from-slate-700 to-slate-600 rounded-xl p-6 text-white shadow-lg">
        <div class="flex justify-between items-start">
            <div>
                <h1 class="text-3xl font-bold mb-2">Category Management</h1>
                <p class="text-slate-200 opacity-90">Organize your monitoring checks into logical groups</p>
            </div>
            <div class="flex gap-3">
                <button onclick="openCategoryModal()" 
                        class="px-5 py-2.5 bg-white text-slate-600 rounded-lg hover:bg-slate-50 transition-all flex items-center gap-2 font-medium shadow-sm">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/>
                    </svg>
                    Add Category
                </button>
            </div>
        </div>
        
        <!-- Stats -->
        <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mt-6">
            <div class="bg-white/15 backdrop-blur border border-white/20 rounded-lg p-4 hover:bg-white/20 transition-colors">
                <div class="text-3xl font-bold"><?php echo count($categories); ?></div>
                <div class="text-slate-200 text-sm opacity-90">Total Categories</div>
            </div>
            <div class="bg-white/15 backdrop-blur border border-white/20 rounded-lg p-4 hover:bg-white/20 transition-colors">
                <div class="text-3xl font-bold"><?php echo array_sum(array_column($categories, 'total_checks')); ?></div>
                <div class="text-slate-200 text-sm opacity-90">Total Checks</div>
            </div>
            <div class="bg-white/15 backdrop-blur border border-white/20 rounded-lg p-4 hover:bg-white/20 transition-colors">
                <div class="text-3xl font-bold text-emerald-300"><?php echo array_sum(array_column($categories, 'up_checks')); ?></div>
                <div class="text-slate-200 text-sm opacity-90">Up</div>
            </div>
            <div class="bg-white/15 backdrop-blur border border-white/20 rounded-lg p-4 hover:bg-white/20 transition-colors">
                <div class="text-3xl font-bold text-red-300"><?php echo array_sum(array_column($categories, 'down_checks')); ?></div>
                <div class="text-slate-200 text-sm opacity-90">Down</div>
            </div>
        </div>
    </div>

    <!-- Categories Grid -->
    <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
        <h2 class="text-lg font-semibold text-gray-900 mb-4">Categories (<?php echo count($categories); ?>)</h2>
        
        <?php if (empty($categories)): ?>
            <div class="text-center py-12 bg-gray-50 rounded-lg">
                <svg class="w-16 h-16 text-gray-300 mx-auto mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 7h.01M7 3h5c.512 0 1.024.195 1.414.586l7 7a2 2 0 010 2.828l-7 7a2 2 0 01-2.828 0l-7-7A1.994 1.994 0 013 12V7a4 4 0 014-4z"/>
                </svg>
                <p class="text-gray-500 text-lg">No categories yet</p>
                <p class="text-gray-400 text-sm mt-2">Create your first category to organize your checks</p>
                <button onclick="openCategoryModal()" class="mt-4 px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700">
                    Add First Category
                </button>
            </div>
        <?php else: ?>
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-6">
                <?php foreach ($categories as $category): ?>
                <div class="card-hover bg-white rounded-xl p-6 relative">
                    <div class="flex items-start justify-between mb-4">
                        <div class="flex items-center gap-3">
                            <div class="w-12 h-12 rounded-lg flex items-center justify-center" 
                                 style="background-color: <?php echo $category['color']; ?>20;">
                                <div class="w-6 h-6 text-xl flex items-center justify-center" 
                                     style="color: <?php echo $category['color']; ?>;">
                                    <?php 
                                    $icons = [
                                        'api' => '<svg class="w-5 h-5" fill="currentColor" viewBox="0 0 20 20"><path d="M3 4a1 1 0 011-1h12a1 1 0 011 1v2a1 1 0 01-1 1H4a1 1 0 01-1-1V4zM3 10a1 1 0 011-1h6a1 1 0 011 1v6a1 1 0 01-1 1H4a1 1 0 01-1-1v-6zM14 9a1 1 0 00-1 1v6a1 1 0 001 1h2a1 1 0 001-1v-6a1 1 0 00-1-1h-2z"/></svg>',
                                        'globe' => '<svg class="w-5 h-5" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM4.332 8.027a6.012 6.012 0 011.912-2.706C6.512 5.73 6.974 6 7.5 6A1.5 1.5 0 019 7.5V8a2 2 0 004 0 2 2 0 011.523-1.943A5.977 5.977 0 0116 10c0 .34-.028.675-.083 1H15a2 2 0 00-2 2v2.197A5.973 5.973 0 0110 16v-2a2 2 0 00-2-2 2 2 0 01-2-2 2 2 0 00-1.668-1.973z" clip-rule="evenodd"/></svg>',
                                        'database' => '<svg class="w-5 h-5" fill="currentColor" viewBox="0 0 20 20"><path d="M3 12v3c0 1.657 3.134 3 7 3s7-1.343 7-3v-3c0 1.657-3.134 3-7 3s-7-1.343-7-3z"/><path d="M3 7v3c0 1.657 3.134 3 7 3s7-1.343 7-3V7c0 1.657-3.134 3-7 3S3 8.657 3 7z"/><path d="M17 5c0 1.657-3.134 3-7 3S3 6.657 3 5s3.134-3 7-3 7 1.343 7 3z"/></svg>',
                                        'external-link' => '<svg class="w-5 h-5" fill="currentColor" viewBox="0 0 20 20"><path d="M11 3a1 1 0 100 2h2.586l-6.293 6.293a1 1 0 101.414 1.414L15 6.414V9a1 1 0 102 0V4a1 1 0 00-1-1h-5z"/><path d="M5 5a2 2 0 00-2 2v8a2 2 0 002 2h8a2 2 0 002-2v-3a1 1 0 10-2 0v3H5V7h3a1 1 0 000-2H5z"/></svg>',
                                        'activity' => '<svg class="w-5 h-5" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M3 3a1 1 0 000 2v8a2 2 0 002 2h2.586l-1.293 1.293a1 1 0 101.414 1.414L10 15.414l2.293 2.293a1 1 0 001.414-1.414L12.414 15H15a2 2 0 002-2V5a1 1 0 100-2H3zm11.707 4.707a1 1 0 00-1.414-1.414L10 9.586 8.707 8.293a1 1 0 00-1.414 0l-2 2a1 1 0 101.414 1.414L8 10.414l1.293 1.293a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/></svg>',
                                        'folder' => '<svg class="w-5 h-5" fill="currentColor" viewBox="0 0 20 20"><path d="M2 6a2 2 0 012-2h5l2 2h5a2 2 0 012 2v6a2 2 0 01-2 2H4a2 2 0 01-2-2V6z"/></svg>'
                                    ];
                                    echo $icons[$category['icon']] ?? $icons['folder'];
                                    ?>
                                </div>
                            </div>
                            <div>
                                <h3 class="text-lg font-semibold text-gray-900"><?php echo htmlspecialchars($category['name']); ?></h3>
                                <?php if ($category['description']): ?>
                                    <p class="text-sm text-gray-500"><?php echo htmlspecialchars($category['description']); ?></p>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="relative">
                            <button onclick="showCategoryActions(<?php echo $category['id']; ?>, '<?php echo htmlspecialchars(addslashes($category['name'])); ?>', <?php echo $category['total_checks']; ?>)" 
                                    class="p-2 hover:bg-gray-100 rounded-full transition-colors">
                                <svg class="w-5 h-5 text-gray-400" fill="currentColor" viewBox="0 0 20 20">
                                    <path d="M10 6a2 2 0 110-4 2 2 0 010 4zM10 12a2 2 0 110-4 2 2 0 010 4zM10 18a2 2 0 110-4 2 2 0 010 4z"/>
                                </svg>
                            </button>
                        </div>
                    </div>
                    
                    <!-- Stats -->
                    <div class="grid grid-cols-3 gap-4 mt-4">
                        <div class="text-center">
                            <div class="text-2xl font-bold text-gray-900"><?php echo $category['total_checks']; ?></div>
                            <div class="text-xs text-gray-500">Total</div>
                        </div>
                        <div class="text-center">
                            <div class="text-2xl font-bold text-green-600"><?php echo $category['up_checks']; ?></div>
                            <div class="text-xs text-gray-500">Up</div>
                        </div>
                        <div class="text-center">
                            <div class="text-2xl font-bold text-red-600"><?php echo $category['down_checks']; ?></div>
                            <div class="text-xs text-gray-500">Down</div>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- Category Modal -->
<div id="categoryModal" class="hidden fixed inset-0 bg-gray-900 bg-opacity-50 overflow-y-auto h-full w-full z-50">
    <div class="relative top-20 mx-auto p-5 border w-96 shadow-2xl rounded-xl bg-white">
        <div class="mt-3">
            <h3 class="text-xl font-bold text-gray-900 mb-4" id="modalTitle">Create New Category</h3>
            
            <form id="categoryForm" method="POST">
                <input type="hidden" name="csrf_token" value="<?php echo $auth->getCsrfToken(); ?>">
                <input type="hidden" name="action" value="create" id="formAction">
                <input type="hidden" name="category_id" value="" id="categoryId">
                
                <div class="space-y-4">
                    <div>
                        <label for="categoryName" class="block text-sm font-medium text-gray-700 mb-1">Category Name</label>
                        <input type="text" id="categoryName" name="name" required maxlength="50"
                               placeholder="e.g., Production APIs"
                               class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-gray-500 focus:border-transparent">
                    </div>
                    
                    <div>
                        <label for="categoryIcon" class="block text-sm font-medium text-gray-700 mb-1">Icon</label>
                        <select id="categoryIcon" name="icon" 
                                class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-gray-500">
                            <?php foreach ($iconOptions as $value => $label): ?>
                                <option value="<?php echo $value; ?>"><?php echo $label; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">Color</label>
                        <div class="grid grid-cols-4 gap-2">
                            <?php foreach ($colorOptions as $color => $name): ?>
                            <div>
                                <input type="radio" name="color" value="<?php echo $color; ?>" id="color_<?php echo $name; ?>" class="sr-only">
                                <label for="color_<?php echo $name; ?>" class="color-option block" 
                                       style="background-color: <?php echo $color; ?>;" 
                                       title="<?php echo $name; ?>"></label>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    
                    <div>
                        <label for="categoryDesc" class="block text-sm font-medium text-gray-700 mb-1">Description (Optional)</label>
                        <textarea id="categoryDesc" name="description" rows="3"
                                  placeholder="Brief description of this category..."
                                  class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-gray-500 resize-none"></textarea>
                    </div>
                </div>
                
                <div class="mt-6 flex justify-end space-x-3">
                    <button type="button" onclick="closeCategoryModal()" 
                            class="px-5 py-2.5 border border-gray-300 rounded-lg text-gray-700 hover:bg-gray-50 transition-colors">
                        Cancel
                    </button>
                    <button type="submit" 
                            class="px-5 py-2.5 bg-gradient-to-r from-slate-600 to-slate-700 text-white rounded-lg hover:from-slate-700 hover:to-slate-800 transition-all">
                        <span id="submitText">Create Category</span>
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Category Actions Modal -->
<div id="categoryActionsModal" class="hidden fixed inset-0 bg-black bg-opacity-50 z-50 flex items-center justify-center">
    <div class="bg-white rounded-lg shadow-xl w-64 transform transition-all">
        <div class="p-4 border-b border-gray-200">
            <h3 class="text-lg font-semibold text-gray-900" id="actionsModalTitle">Category Actions</h3>
        </div>
        
        <div class="py-2">
            <button onclick="editCategory()" class="flex items-center w-full px-4 py-3 text-sm text-gray-700 hover:bg-gray-50 transition-colors">
                <div class="w-10 h-10 bg-blue-100 rounded-full flex items-center justify-center mr-3">
                    <svg class="w-5 h-5 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/>
                    </svg>
                </div>
                <div class="flex-1 text-left">
                    <div class="font-medium">Edit Category</div>
                    <div class="text-xs text-gray-500">Modify category settings</div>
                </div>
            </button>
            
            <button onclick="viewCategoryChecks()" class="flex items-center w-full px-4 py-3 text-sm text-gray-700 hover:bg-gray-50 transition-colors">
                <div class="w-10 h-10 bg-green-100 rounded-full flex items-center justify-center mr-3">
                    <svg class="w-5 h-5 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v10a2 2 0 002 2h8a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/>
                    </svg>
                </div>
                <div class="flex-1 text-left">
                    <div class="font-medium">View Checks</div>
                    <div class="text-xs text-gray-500">See all checks in this category</div>
                </div>
            </button>
            
            <div class="border-t border-gray-100 mt-2"></div>
            
            <button onclick="deleteCategoryConfirm()" class="flex items-center w-full px-4 py-3 text-sm text-red-600 hover:bg-red-50 transition-colors">
                <div class="w-10 h-10 bg-red-100 rounded-full flex items-center justify-center mr-3">
                    <svg class="w-5 h-5 text-red-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1-1H8a1 1 0 00-1 1v3M4 7h16"/>
                    </svg>
                </div>
                <div class="flex-1 text-left">
                    <div class="font-medium">Delete Category</div>
                    <div class="text-xs text-gray-500">Permanently remove this category</div>
                </div>
            </button>
        </div>
        
        <div class="p-4 border-t border-gray-200">
            <button onclick="closeCategoryActionsModal()" class="w-full px-4 py-2 bg-gray-100 text-gray-700 rounded-lg hover:bg-gray-200 transition-colors">
                Cancel
            </button>
        </div>
    </div>
</div>

<script>
let currentCategoryId = null;
let currentCategoryName = null;
let currentCategoryCheckCount = 0;

function openCategoryModal() {
    document.getElementById('categoryModal').classList.remove('hidden');
    document.getElementById('categoryName').focus();
    resetForm();
}

function closeCategoryModal() {
    document.getElementById('categoryModal').classList.add('hidden');
    resetForm();
}

function resetForm() {
    document.getElementById('categoryForm').reset();
    document.getElementById('formAction').value = 'create';
    document.getElementById('categoryId').value = '';
    document.getElementById('modalTitle').textContent = 'Create New Category';
    document.getElementById('submitText').textContent = 'Create Category';
    
    // Select first color by default
    document.querySelector('input[name="color"]').checked = true;
    updateColorSelection();
}

function showCategoryActions(id, name, checkCount) {
    currentCategoryId = id;
    currentCategoryName = name;
    currentCategoryCheckCount = checkCount;
    
    document.getElementById('actionsModalTitle').textContent = name;
    document.getElementById('categoryActionsModal').classList.remove('hidden');
    document.body.style.overflow = 'hidden';
}

function closeCategoryActionsModal() {
    document.getElementById('categoryActionsModal').classList.add('hidden');
    document.body.style.overflow = 'auto';
    currentCategoryId = null;
    currentCategoryName = null;
    currentCategoryCheckCount = 0;
}

function editCategory() {
    if (!currentCategoryId) return;
    
    // Fetch category data and populate form
    fetch(`/api/category/${currentCategoryId}`)
        .then(response => response.json())
        .then(data => {
            document.getElementById('categoryName').value = data.name;
            document.getElementById('categoryIcon').value = data.icon;
            document.getElementById('categoryDesc').value = data.description || '';
            
            // Select the correct color
            document.querySelector(`input[name="color"][value="${data.color}"]`).checked = true;
            updateColorSelection();
            
            // Update form for editing
            document.getElementById('formAction').value = 'update';
            document.getElementById('categoryId').value = currentCategoryId;
            document.getElementById('modalTitle').textContent = 'Edit Category';
            document.getElementById('submitText').textContent = 'Update Category';
            
            closeCategoryActionsModal();
            openCategoryModal();
        })
        .catch(error => {
            console.error('Error fetching category:', error);
            alert('Failed to load category data');
        });
}

function viewCategoryChecks() {
    if (currentCategoryId) {
        window.location.href = `/checks.php?categories[]=${currentCategoryId}`;
    }
    closeCategoryActionsModal();
}

function deleteCategoryConfirm() {
    if (!currentCategoryId || !currentCategoryName) return;
    
    closeCategoryActionsModal();
    
    if (currentCategoryCheckCount > 0) {
        alert(`Cannot delete "${currentCategoryName}" because it has ${currentCategoryCheckCount} assigned checks. Please reassign them first.`);
        return;
    }
    
    if (confirm(`Are you sure you want to delete category "${currentCategoryName}"?`)) {
        const form = document.createElement('form');
        form.method = 'POST';
        form.innerHTML = `
            <input type="hidden" name="csrf_token" value="<?php echo $auth->getCsrfToken(); ?>">
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="category_id" value="${currentCategoryId}">
        `;
        document.body.appendChild(form);
        form.submit();
    }
}

// Color selection handling
document.addEventListener('DOMContentLoaded', function() {
    const colorOptions = document.querySelectorAll('.color-option');
    colorOptions.forEach(option => {
        option.addEventListener('click', function() {
            const radio = document.getElementById(this.getAttribute('for'));
            radio.checked = true;
            updateColorSelection();
        });
    });
    
    // Set initial selection
    updateColorSelection();
});

function updateColorSelection() {
    document.querySelectorAll('.color-option').forEach(option => {
        option.classList.remove('selected');
    });
    
    const selectedRadio = document.querySelector('input[name="color"]:checked');
    if (selectedRadio) {
        const label = document.querySelector(`label[for="${selectedRadio.id}"]`);
        if (label) {
            label.classList.add('selected');
        }
    }
}

// Close modals on outside click
document.addEventListener('click', function(event) {
    const modal = document.getElementById('categoryModal');
    if (event.target === modal) {
        closeCategoryModal();
    }
    
    const actionsModal = document.getElementById('categoryActionsModal');
    if (event.target === actionsModal) {
        closeCategoryActionsModal();
    }
});

// Close modals on Escape key
document.addEventListener('keydown', function(event) {
    if (event.key === 'Escape') {
        closeCategoryModal();
        closeCategoryActionsModal();
    }
});
</script>

<?php
$content = ob_get_clean();
renderTemplate('Category Management', $content);
?>
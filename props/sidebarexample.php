<?php
$currentPage = isset($_GET['page']) ? $_GET['page'] : 'dashboard';

// Define sidebar sections and their pages
$sidebarSections = [
    'users' => ['users', 'add_user', 'edit_user', 'delete_user'],
    'school' => ['all_schools', 'add_school', 'edit_school', 'school_management', 'school_analytics', 'school_settings'],
    'teachers' => ['teachers', 'add_teacher', 'edit_teacher', 'view_teacher', 'delete_teacher'],
    'parents' => ['all_parents', 'add_parent', 'edit_parent', 'view_parent', 'delete_parent'],
    'learners' => ['all_learners', 'add_learner', 'edit_learner', 'promote_learner', 'view_learner', 'delete_learner'],
    'admissions' => ['admissions', 'view_application', 'approve_application', 'reject_application'],
    'academics' => ['classes', 'courses', 'lessons', 'timetable'],
    'attendance' => ['mark_attendance', 'attendance_report', 'view_attendance'],
    'exams' => ['create_exams', 'grades', 'enter_results', 'report_cards'],
    'fees' => ['fee_structure', 'payments', 'invoices', 'dues'],
    'reports' => ['learner_reports', 'teacher_reports', 'financial_reports', 'attendance_reports', 'exam_reports'],
    'communication' => ['announcements', 'messages', 'notifications']
];

// Determine which section should be expanded
$expandedSection = null;
foreach ($sidebarSections as $sectionId => $pages) {
    if (in_array($currentPage, $pages)) {
        $expandedSection = $sectionId;
        break;
    }
}

function sidebar_link_classes($isActive) {
    return 'sidebar-link group flex items-center p-3 rounded-lg transition-all duration-200 ease-in-out ' .
        ($isActive
            ? 'text-[#00b4d8] bg-[#e0f7fa] shadow-sm'
            : 'text-gray-600 hover:text-[#00b4d8] hover:bg-[#e0f7fa]');
}

function sidebar_subsection_classes($isActive) {
    return 'sidebar-link group flex items-center p-2 ml-4 rounded-lg text-sm transition-all duration-200 ease-in-out ' .
        ($isActive
            ? 'text-[#00b4d8] bg-[#e0f7fa] shadow-sm'
            : 'text-gray-500 hover:text-[#00b4d8] hover:bg-[#e0f7fa]');
}

function section_header_classes($sectionId, $expandedSection) {
    $isExpanded = ($sectionId === $expandedSection);
    // Use Tailwind's premium/professional greys: slate-700 for active, slate-400 for inactive, subtle bg for active
    return 'section-header flex items-center justify-between px-3 py-2 cursor-pointer transition-all duration-200 ease-in-out rounded-lg ' .
           ($isExpanded
                ? 'text-slate-700 bg-slate-50 shadow-sm'
                : 'text-slate-400 hover:text-slate-700 hover:bg-slate-50');
}

function home_button_classes($isActive) {
    // Use the same styling as section headers but for navigation
    return 'flex items-center px-3 py-2 transition-all duration-200 ease-in-out rounded-lg ' .
           ($isActive
                ? 'text-slate-700 bg-slate-50 shadow-sm'
                : 'text-slate-400 hover:text-slate-700 hover:bg-slate-50');
}

function chevron_classes($sectionId, $expandedSection) {
    $isExpanded = ($sectionId === $expandedSection);
    return 'chevron w-4 h-4 transition-transform duration-300 ease-in-out ' . 
           ($isExpanded ? 'transform rotate-90' : '');
}
?>

<style>
/* Optimized scrollbar styling */
.sidebar-nav::-webkit-scrollbar {
    width: 2px;
}

.sidebar-nav::-webkit-scrollbar-track {
    background: transparent;
}

.sidebar-nav::-webkit-scrollbar-thumb {
    background: #e5e7eb;
    border-radius: 1px;
}

.sidebar-nav::-webkit-scrollbar-thumb:hover {
    background: #d1d5db;
}

/* Firefox scrollbar */
.sidebar-nav {
    scrollbar-width: thin;
    scrollbar-color: #e5e7eb transparent;
}

/* Prevent text selection and dragging */
.sidebar-link {
    user-select: none;
    -webkit-user-select: none;
    -moz-user-select: none;
    -ms-user-select: none;
    -webkit-user-drag: none;
    -khtml-user-drag: none;
    -moz-user-drag: none;
    -o-user-drag: none;
    user-drag: none;
}

/* Section animations - optimized for performance */
.section-content {
    overflow: hidden;
    transition: max-height 0.3s cubic-bezier(0.4, 0, 0.2, 1);
    will-change: max-height;
}

.section-content:not(.expanded) {
    max-height: 0;
}

.section-content.expanded {
    max-height: 400px;
}

/* Prevent layout shift during animations */
.section-header {
    min-height: 32px;
}

/* Optimize hover effects */
.sidebar-link:hover {
    transform: translateX(1px);
}

.sidebar-link:hover svg {
    transform: scale(1.05);
}

/* Icon transitions */
.sidebar-link svg {
    transition: transform 0.2s ease-in-out;
}

/* Blue-25 custom color for subtle highlighting */
.bg-blue-25 {
    background-color: #f0f9ff;
}

/* Smooth scrolling */
.sidebar-nav {
    scroll-behavior: smooth;
    overscroll-behavior: contain;
}

/* Performance optimizations */
.sidebar-container {
    contain: layout style;
}

/* Loading state prevention */
.sidebar-loading .section-content {
    transition: none;
}
</style>

<div class="sidebar-container bg-white h-full flex flex-col border-r border-gray-200">
    <!-- Header -->
    <div class="p-4 border-b border-gray-200 flex items-center flex-shrink-0">
        <img src="/uploads/IMG-20241126-WA0454 (2) (1).jpg" alt="NAMEDUCONECT" class="h-8 w-auto">
        <p class="text-sm text-gray-600 ml-4 font-medium">Admin</p>
    </div>

    <!-- Navigation -->
    <nav class="sidebar-nav flex-1 overflow-y-auto mt-6" style="height: calc(100vh - 140px);">
        <div class="px-4 space-y-1 pb-6">
            
            <!-- Dashboard (always visible) -->
            <a href="?page=dashboard" class="<?php echo home_button_classes($currentPage === 'dashboard'); ?>">
                <span class="text-xs font-semibold uppercase tracking-wider">Home</span>
            </a>

            <!-- Users Section -->
            <div class="mt-6">
                <div class="<?php echo section_header_classes('users', $expandedSection); ?>" onclick="toggleSection('users')">
                    <span class="text-xs font-semibold uppercase tracking-wider">Users</span>
                    <svg class="<?php echo chevron_classes('users', $expandedSection); ?>" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path>
                    </svg>
                </div>
                <div class="section-content mt-2 space-y-1 <?php echo ($expandedSection === 'users') ? 'expanded' : ''; ?>" id="users-content">
                    <a href="?page=users" class="<?php echo sidebar_subsection_classes($currentPage === 'users'); ?>">
                        <svg class="w-4 h-4 mr-2 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"></path>
                        </svg>
                        All Users
                    </a>
                    <a href="?page=add_user" class="<?php echo sidebar_subsection_classes($currentPage === 'add_user'); ?>">
                        <svg class="w-4 h-4 mr-2 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6"></path>
                        </svg>
                        Add User
                    </a>
                    <a href="?page=edit_user" class="<?php echo sidebar_subsection_classes($currentPage === 'edit_user'); ?>">
                        <svg class="w-4 h-4 mr-2 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"></path>
                        </svg>
                        Edit User
                    </a>
                    <a href="?page=delete_user" class="<?php echo sidebar_subsection_classes($currentPage === 'delete_user'); ?>">
                        <svg class="w-4 h-4 mr-2 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path>
                        </svg>
                        Delete User
                    </a>
                </div>
            </div>

            <!-- School Section -->
            <div class="mt-4">
                <div class="<?php echo section_header_classes('school', $expandedSection); ?>" onclick="toggleSection('school')">
                    <span class="text-xs font-semibold uppercase tracking-wider">School</span>
                    <svg class="<?php echo chevron_classes('school', $expandedSection); ?>" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path>
                    </svg>
                </div>
                <div class="section-content mt-2 space-y-1 <?php echo ($expandedSection === 'school') ? 'expanded' : ''; ?>" id="school-content">
                    <a href="?page=all_schools" class="<?php echo sidebar_subsection_classes($currentPage === 'all_schools'); ?>">
                        <svg class="w-4 h-4 mr-2 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"></path>
                        </svg>
                        All Schools
                    </a>
                    <a href="?page=add_school" class="<?php echo sidebar_subsection_classes($currentPage === 'add_school'); ?>">
                        <svg class="w-4 h-4 mr-2 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6"></path>
                        </svg>
                        Add School
                    </a>
                    <a href="?page=edit_school" class="<?php echo sidebar_subsection_classes($currentPage === 'edit_school'); ?>">
                        <svg class="w-4 h-4 mr-2 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"></path>
                        </svg>
                        Edit School
                    </a>

                    <a href="?page=classes" class="<?php echo sidebar_subsection_classes($currentPage === 'classes'); ?>">
                        <svg class="w-4 h-4 mr-2 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10"></path>
                        </svg>
                        Classes
                    </a>
                    <a href="?page=school_management" class="<?php echo sidebar_subsection_classes($currentPage === 'school_management'); ?>">
                        <svg class="w-4 h-4 mr-2 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                        </svg>
                        School Management
                    </a>
                    
            
                </div>
            </div>

            <!-- Teachers Section -->
            <div class="mt-4">
                <div class="<?php echo section_header_classes('teachers', $expandedSection); ?>" onclick="toggleSection('teachers')">
                    <span class="text-xs font-semibold uppercase tracking-wider">Teachers</span>
                    <svg class="<?php echo chevron_classes('teachers', $expandedSection); ?>" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path>
                    </svg>
                </div>
                <div class="section-content mt-2 space-y-1 <?php echo ($expandedSection === 'teachers') ? 'expanded' : ''; ?>" id="teachers-content">
                    <a href="?page=teachers" class="<?php echo sidebar_subsection_classes($currentPage === 'teachers'); ?>">
                        <svg class="w-4 h-4 mr-2 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"></path>
                        </svg>
                        All Teachers
                    </a>
                    <a href="?page=add_teacher" class="<?php echo sidebar_subsection_classes($currentPage === 'add_teacher'); ?>">
                        <svg class="w-4 h-4 mr-2 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6"></path>
                        </svg>
                        Add Teacher
                    </a>
                    <a href="?page=edit_teacher" class="<?php echo sidebar_subsection_classes($currentPage === 'edit_teacher'); ?>">
                        <svg class="w-4 h-4 mr-2 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"></path>
                        </svg>
                        Edit Teacher
                    </a>
                    <a href="?page=view_teacher" class="<?php echo sidebar_subsection_classes($currentPage === 'view_teacher'); ?>">
                        <svg class="w-4 h-4 mr-2 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path>
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"></path>
                        </svg>
                        View Teacher
                    </a>
          
                </div>
            </div>

            <!-- Parents Section -->
            <div class="mt-4">
                <div class="<?php echo section_header_classes('parents', $expandedSection); ?>" onclick="toggleSection('parents')">
                    <span class="text-xs font-semibold uppercase tracking-wider">Parents</span>
                    <svg class="<?php echo chevron_classes('parents', $expandedSection); ?>" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path>
                    </svg>
                </div>
                <div class="section-content mt-2 space-y-1 <?php echo ($expandedSection === 'parents') ? 'expanded' : ''; ?>" id="parents-content">
                    <a href="?page=all_parents" class="<?php echo sidebar_subsection_classes($currentPage === 'all_parents'); ?>">
                        <svg class="w-4 h-4 mr-2 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z"></path>
                        </svg>
                        All Parents
                    </a>
                    <a href="?page=add_parent" class="<?php echo sidebar_subsection_classes($currentPage === 'add_parent'); ?>">
                        <svg class="w-4 h-4 mr-2 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6"></path>
                        </svg>
                        Add Parent
                    </a>
                    <a href="?page=edit_parent" class="<?php echo sidebar_subsection_classes($currentPage === 'edit_parent'); ?>">
                        <svg class="w-4 h-4 mr-2 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"></path>
                        </svg>
                        Edit Parent
                    </a>
                    <a href="?page=view_parent" class="<?php echo sidebar_subsection_classes($currentPage === 'view_parent'); ?>">
                        <svg class="w-4 h-4 mr-2 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path>
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"></path>
                        </svg>
                        View Parent
                    </a>
               
                </div>
            </div>

            <!-- learners Section -->
            <div class="mt-4">
                <div class="<?php echo section_header_classes('learners', $expandedSection); ?>" onclick="toggleSection('learners')">
                    <span class="text-xs font-semibold uppercase tracking-wider">learners</span>
                    <svg class="<?php echo chevron_classes('learners', $expandedSection); ?>" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path>
                    </svg>
                </div>
                <div class="section-content mt-2 space-y-1 <?php echo ($expandedSection === 'learners') ? 'expanded' : ''; ?>" id="learners-content">
                    <a href="?page=all_learners" class="<?php echo sidebar_subsection_classes($currentPage === 'all_learners'); ?>">
                        <svg class="w-4 h-4 mr-2 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"></path>
                        </svg>
                        All learners
                    </a>
                    <a href="?page=add_learner" class="<?php echo sidebar_subsection_classes($currentPage === 'add_learner'); ?>">
                        <svg class="w-4 h-4 mr-2 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6"></path>
                        </svg>
                        Add learner
                    </a>
                    <a href="?page=edit_learner" class="<?php echo sidebar_subsection_classes($currentPage === 'edit_learner'); ?>">
                        <svg class="w-4 h-4 mr-2 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"></path>
                        </svg>
                        Edit learner
                    </a>
                    <a href="?page=promote_learner" class="<?php echo sidebar_subsection_classes($currentPage === 'promote_learner'); ?>">
                        <svg class="w-4 h-4 mr-2 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 16V4m0 0L3 8m4-4l4 4m6 0v12m0 0l4-4m-4 4l-4-4"></path>
                        </svg>
                        Promote learner
                    </a>
                    <a href="?page=view_learner" class="<?php echo sidebar_subsection_classes($currentPage === 'view_learner'); ?>">
                        <svg class="w-4 h-4 mr-2 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path>
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"></path>
                        </svg>
                        View learner
                    </a>
              
                </div>
            </div>

            <!-- Admissions Section -->
            <div class="mt-4">
                <div class="<?php echo section_header_classes('admissions', $expandedSection); ?>" onclick="toggleSection('admissions')">
                    <span class="text-xs font-semibold uppercase tracking-wider">Admissions</span>
                    <svg class="<?php echo chevron_classes('admissions', $expandedSection); ?>" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path>
                    </svg>
                </div>
                <div class="section-content mt-2 space-y-1 <?php echo ($expandedSection === 'admissions') ? 'expanded' : ''; ?>" id="admissions-content">
                    <a href="?page=admissions" class="<?php echo sidebar_subsection_classes($currentPage === 'admissions'); ?>">
                        <svg class="w-4 h-4 mr-2 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
                        </svg>
                        All Applications
                    </a>
                    <a href="?page=view_application" class="<?php echo sidebar_subsection_classes($currentPage === 'view_application'); ?>">
                        <svg class="w-4 h-4 mr-2 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path>
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"></path>
                        </svg>
                        View Application
                    </a>
                    <a href="?page=approve_application" class="<?php echo sidebar_subsection_classes($currentPage === 'approve_application'); ?>">
                        <svg class="w-4 h-4 mr-2 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
                        </svg>
                        Approve Application
                    </a>
                    <a href="?page=reject_application" class="<?php echo sidebar_subsection_classes($currentPage === 'reject_application'); ?>">
                        <svg class="w-4 h-4 mr-2 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                        </svg>
                        Reject Application
                    </a>
                </div>
            </div>

            <!-- Academics Section -->
            <div class="mt-4">
                <div class="<?php echo section_header_classes('academics', $expandedSection); ?>" onclick="toggleSection('academics')">
                    <span class="text-xs font-semibold uppercase tracking-wider">Academics</span>
                    <svg class="<?php echo chevron_classes('academics', $expandedSection); ?>" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path>
                    </svg>
                </div>
                <div class="section-content mt-2 space-y-1 <?php echo ($expandedSection === 'academics') ? 'expanded' : ''; ?>" id="academics-content">
 
                    <a href="?page=courses" class="<?php echo sidebar_subsection_classes($currentPage === 'courses'); ?>">
                        <svg class="w-4 h-4 mr-2 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 14l9-5-9-5-9 5 9 5zM12 14v7"></path>
                        </svg>
                        Courses
                    </a>
                    <a href="?page=lessons" class="<?php echo sidebar_subsection_classes($currentPage === 'lessons'); ?>">
                        <svg class="w-4 h-4 mr-2 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 20l9-5-9-5-9 5 9 5z"></path>
                        </svg>
                        Lessons
                    </a>
                    <a href="?page=timetable" class="<?php echo sidebar_subsection_classes($currentPage === 'timetable'); ?>">
                        <svg class="w-4 h-4 mr-2 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"></path>
                        </svg>
                        Timetable
                    </a>
                </div>
            </div>

            <!-- Attendance Section -->
            <div class="mt-4">
                <div class="<?php echo section_header_classes('attendance', $expandedSection); ?>" onclick="toggleSection('attendance')">
                    <span class="text-xs font-semibold uppercase tracking-wider">Attendance</span>
                    <svg class="<?php echo chevron_classes('attendance', $expandedSection); ?>" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path>
                    </svg>
                </div>
                <div class="section-content mt-2 space-y-1 <?php echo ($expandedSection === 'attendance') ? 'expanded' : ''; ?>" id="attendance-content">
                    <a href="?page=mark_attendance" class="<?php echo sidebar_subsection_classes($currentPage === 'mark_attendance'); ?>">
                        <svg class="w-4 h-4 mr-2 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                        </svg>
                        Mark Attendance
                    </a>
                    <a href="?page=attendance_report" class="<?php echo sidebar_subsection_classes($currentPage === 'attendance_report'); ?>">
                        <svg class="w-4 h-4 mr-2 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"></path>
                        </svg>
                        Attendance Report
                    </a>
                    <a href="?page=view_attendance" class="<?php echo sidebar_subsection_classes($currentPage === 'view_attendance'); ?>">
                        <svg class="w-4 h-4 mr-2 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path>
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"></path>
                        </svg>
                        View Attendance
                    </a>
                </div>
            </div>

            <!-- Exams Section -->
            <div class="mt-4">
                <div class="<?php echo section_header_classes('exams', $expandedSection); ?>" onclick="toggleSection('exams')">
                    <span class="text-xs font-semibold uppercase tracking-wider">Exams</span>
                    <svg class="<?php echo chevron_classes('exams', $expandedSection); ?>" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path>
                    </svg>
                </div>
                <div class="section-content mt-2 space-y-1 <?php echo ($expandedSection === 'exams') ? 'expanded' : ''; ?>" id="exams-content">
                    <a href="?page=create_exams" class="<?php echo sidebar_subsection_classes($currentPage === 'create_exams'); ?>">
                        <svg class="w-4 h-4 mr-2 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6"></path>
                        </svg>
                        Create Exams
                    </a>
                    <a href="?page=grades" class="<?php echo sidebar_subsection_classes($currentPage === 'grades'); ?>">
                        <svg class="w-4 h-4 mr-2 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
                        </svg>
                        Grades
                    </a>
                    <a href="?page=enter_results" class="<?php echo sidebar_subsection_classes($currentPage === 'enter_results'); ?>">
                        <svg class="w-4 h-4 mr-2 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"></path>
                        </svg>
                        Enter Results
                    </a>
                    <a href="?page=report_cards" class="<?php echo sidebar_subsection_classes($currentPage === 'report_cards'); ?>">
                        <svg class="w-4 h-4 mr-2 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
                        </svg>
                        Report Cards
                    </a>
                </div>
            </div>

            <!-- Fees Section -->
            <div class="mt-4">
                <div class="<?php echo section_header_classes('fees', $expandedSection); ?>" onclick="toggleSection('fees')">
                    <span class="text-xs font-semibold uppercase tracking-wider">Fees</span>
                    <svg class="<?php echo chevron_classes('fees', $expandedSection); ?>" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path>
                    </svg>
                </div>
                <div class="section-content mt-2 space-y-1 <?php echo ($expandedSection === 'fees') ? 'expanded' : ''; ?>" id="fees-content">
                    <a href="?page=fee_structure" class="<?php echo sidebar_subsection_classes($currentPage === 'fee_structure'); ?>">
                        <svg class="w-4 h-4 mr-2 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v10a2 2 0 002 2h8a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"></path>
                        </svg>
                        Fee Structure
                    </a>
                    <a href="?page=payments" class="<?php echo sidebar_subsection_classes($currentPage === 'payments'); ?>">
                        <svg class="w-4 h-4 mr-2 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 9V7a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2m2 4h10a2 2 0 002-2v-6a2 2 0 00-2-2H9a2 2 0 00-2 2v6a2 2 0 002 2zm7-5a2 2 0 11-4 0 2 2 0 014 0z"></path>
                        </svg>
                        Payments
                    </a>
                    <a href="?page=invoices" class="<?php echo sidebar_subsection_classes($currentPage === 'invoices'); ?>">
                        <svg class="w-4 h-4 mr-2 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
                        </svg>
                        Invoices
                    </a>
                    <a href="?page=dues" class="<?php echo sidebar_subsection_classes($currentPage === 'dues'); ?>">
                        <svg class="w-4 h-4 mr-2 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1"></path>
                        </svg>
                        Dues
                    </a>
                </div>
            </div>

            <!-- Reports Section -->
            <div class="mt-4">
                <div class="<?php echo section_header_classes('reports', $expandedSection); ?>" onclick="toggleSection('reports')">
                    <span class="text-xs font-semibold uppercase tracking-wider">Reports</span>
                    <svg class="<?php echo chevron_classes('reports', $expandedSection); ?>" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path>
                    </svg>
                </div>
                <div class="section-content mt-2 space-y-1 <?php echo ($expandedSection === 'reports') ? 'expanded' : ''; ?>" id="reports-content">
                    <a href="?page=learner_reports" class="<?php echo sidebar_subsection_classes($currentPage === 'learner_reports'); ?>">
                        <svg class="w-4 h-4 mr-2 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"></path>
                        </svg>
                        learner Reports
                    </a>
                    <a href="?page=teacher_reports" class="<?php echo sidebar_subsection_classes($currentPage === 'teacher_reports'); ?>">
                        <svg class="w-4 h-4 mr-2 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z"></path>
                        </svg>
                        Teacher Reports
                    </a>
                    <a href="?page=financial_reports" class="<?php echo sidebar_subsection_classes($currentPage === 'financial_reports'); ?>">
                        <svg class="w-4 h-4 mr-2 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1"></path>
                        </svg>
                        Financial Reports
                    </a>
                    <a href="?page=attendance_reports" class="<?php echo sidebar_subsection_classes($currentPage === 'attendance_reports'); ?>">
                        <svg class="w-4 h-4 mr-2 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                        </svg>
                        Attendance Reports
                    </a>
                    <a href="?page=exam_reports" class="<?php echo sidebar_subsection_classes($currentPage === 'exam_reports'); ?>">
                        <svg class="w-4 h-4 mr-2 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 17v-2m3 2v-4m3 4v-6m2 10H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
                        </svg>
                        Exam Reports
                    </a>
                </div>
            </div>

            <!-- Communication Section -->
            <div class="mt-4">
                <div class="<?php echo section_header_classes('communication', $expandedSection); ?>" onclick="toggleSection('communication')">
                    <span class="text-xs font-semibold uppercase tracking-wider">Communication</span>
                    <svg class="<?php echo chevron_classes('communication', $expandedSection); ?>" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path>
                    </svg>
                </div>
                <div class="section-content mt-2 space-y-1 <?php echo ($expandedSection === 'communication') ? 'expanded' : ''; ?>" id="communication-content">
                
                    <a href="?page=messages" class="<?php echo sidebar_subsection_classes($currentPage === 'messages'); ?>">
                        <svg class="w-4 h-4 mr-2 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 10h.01M12 10h.01M16 10h.01M9 16H5a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v8a2 2 0 01-2 2h-5l-5 5v-5z"></path>
                        </svg>
                        Messages
                    </a>
                    <a href="?page=notifications" class="<?php echo sidebar_subsection_classes($currentPage === 'notifications'); ?>">
                        <svg class="w-4 h-4 mr-2 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6 6 0 10-12 0v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"></path>
                        </svg>
                        Notifications
                    </a>
                </div>
            </div>

        </div>
    </nav>
</div>

<script>
/**
 * Optimized Sidebar Management System
 * Eliminates flicker, provides smooth transitions, and maintains consistent state
 */
class SidebarManager {
    constructor() {
        this.currentPage = '<?php echo $currentPage; ?>';
        this.expandedSection = '<?php echo $expandedSection ?? "null"; ?>';
        this.sections = {
            'users': ['users', 'add_user', 'edit_user', 'delete_user'],
            'school': ['all_schools', 'add_school', 'edit_school', 'school_management', 'school_analytics', 'school_settings'],
            'teachers': ['teachers', 'add_teacher', 'edit_teacher', 'view_teacher', 'delete_teacher'],
            'parents': ['all_parents', 'add_parent', 'edit_parent', 'view_parent', 'delete_parent'],
            'learners': ['all_learners', 'add_learner', 'edit_learner', 'promote_learner', 'view_learner', 'delete_learner'],
            'admissions': ['admissions', 'view_application', 'approve_application', 'reject_application'],
            'academics': ['classes', 'courses', 'lessons', 'timetable'],
            'attendance': ['mark_attendance', 'attendance_report', 'view_attendance'],
            'exams': ['create_exams', 'grades', 'enter_results', 'report_cards'],
            'fees': ['fee_structure', 'payments', 'invoices', 'dues'],
            'reports': ['learner_reports', 'teacher_reports', 'financial_reports', 'attendance_reports', 'exam_reports'],
            'communication': ['announcements', 'messages', 'notifications']
        };
        this.isAnimating = false;
        this.animationTimeout = null;
    }

    init() {
        // Ensure proper initial state without animation
        this.setInitialState();
        
        // Add event listeners
        this.attachEventListeners();
        
        // Optimize performance
        this.optimizeForPerformance();
    }

    setInitialState() {
        const sidebar = document.querySelector('.sidebar-container');
        if (!sidebar) return;

        // Temporarily disable transitions for instant setup
        sidebar.classList.add('sidebar-loading');
        
        // Set up initial expanded state based on PHP
        if (this.expandedSection && this.expandedSection !== 'null') {
            this.expandSection(this.expandedSection, false);
        }
        
        // Re-enable transitions after a frame
        requestAnimationFrame(() => {
            sidebar.classList.remove('sidebar-loading');
        });
    }

    attachEventListeners() {
        // Attach click handlers to section headers
        document.querySelectorAll('.section-header').forEach(header => {
            header.addEventListener('click', (e) => {
                e.preventDefault();
                const sectionId = this.getSectionIdFromHeader(header);
                if (sectionId) {
                    this.toggleSection(sectionId);
                }
            });
        });

        // Handle page navigation
        document.addEventListener('click', (e) => {
            const link = e.target.closest('a[href*="?page="]');
            if (link && link.closest('.sidebar-container')) {
                // Let navigation happen, state will be maintained by PHP
                this.preserveScrollPosition();
            }
        });

        // Handle browser back/forward
        window.addEventListener('popstate', () => {
            // Reinitialize after navigation
            setTimeout(() => this.init(), 50);
        });
    }

    getSectionIdFromHeader(header) {
        const content = header.nextElementSibling;
        return content ? content.id.replace('-content', '') : null;
    }

    toggleSection(sectionId) {
        if (this.isAnimating) return;

        const content = document.getElementById(`${sectionId}-content`);
        const chevron = document.querySelector(`[onclick*="${sectionId}"] .chevron`);
        
        if (!content || !chevron) return;

        this.isAnimating = true;
        
        // Clear any existing timeout
        if (this.animationTimeout) {
            clearTimeout(this.animationTimeout);
        }

        if (content.classList.contains('expanded')) {
            // Collapse current section
            this.collapseSection(sectionId);
            this.expandedSection = null;
        } else {
            // Collapse all other sections first
            this.collapseAllSections();
            
            // Then expand the target section
            setTimeout(() => {
                this.expandSection(sectionId, true);
                this.expandedSection = sectionId;
            }, 50);
        }

        // Reset animation flag
        this.animationTimeout = setTimeout(() => {
            this.isAnimating = false;
        }, 350);
    }

    expandSection(sectionId, animate = true) {
        const content = document.getElementById(`${sectionId}-content`);
        const chevron = document.querySelector(`[onclick*="${sectionId}"] .chevron`);
        
        if (!content || !chevron) return;

        if (!animate) {
            content.style.transition = 'none';
        }

        content.classList.add('expanded');
        chevron.classList.add('transform', 'rotate-90');

        if (!animate) {
            // Re-enable transitions after the change
            requestAnimationFrame(() => {
                content.style.transition = '';
            });
        }
    }

    collapseSection(sectionId) {
        const content = document.getElementById(`${sectionId}-content`);
        const chevron = document.querySelector(`[onclick*="${sectionId}"] .chevron`);
        
        if (!content || !chevron) return;

        content.classList.remove('expanded');
        chevron.classList.remove('transform', 'rotate-90');
    }

    collapseAllSections() {
        Object.keys(this.sections).forEach(sectionId => {
            this.collapseSection(sectionId);
        });
    }

    preserveScrollPosition() {
        const nav = document.querySelector('.sidebar-nav');
        if (nav) {
            sessionStorage.setItem('sidebarScrollTop', nav.scrollTop.toString());
        }
    }

    restoreScrollPosition() {
        const nav = document.querySelector('.sidebar-nav');
        const savedPosition = sessionStorage.getItem('sidebarScrollTop');
        if (nav && savedPosition) {
            nav.scrollTop = parseInt(savedPosition, 10);
            sessionStorage.removeItem('sidebarScrollTop');
        }
    }

    optimizeForPerformance() {
        // Restore scroll position
        this.restoreScrollPosition();

        // Add passive listeners for better scroll performance
        const nav = document.querySelector('.sidebar-nav');
        if (nav) {
            nav.addEventListener('scroll', this.throttle(() => {
                this.preserveScrollPosition();
            }, 100), { passive: true });
        }

        // Preload hover states
        this.preloadHoverStates();
    }

    preloadHoverStates() {
        // Force browser to cache hover transitions
        const links = document.querySelectorAll('.sidebar-link');
        links.forEach(link => {
            link.addEventListener('mouseenter', () => {
                link.style.willChange = 'transform';
            }, { once: true, passive: true });
            
            link.addEventListener('mouseleave', () => {
                link.style.willChange = 'auto';
            }, { passive: true });
        });
    }

    throttle(func, limit) {
        let inThrottle;
        return function() {
            const args = arguments;
            const context = this;
            if (!inThrottle) {
                func.apply(context, args);
                inThrottle = true;
                setTimeout(() => inThrottle = false, limit);
            }
        };
    }
}

// Global function for onclick handlers (backwards compatibility)
let sidebarManager;

function toggleSection(sectionId) {
    if (sidebarManager) {
        sidebarManager.toggleSection(sectionId);
    }
}

// Initialize when DOM is ready
document.addEventListener('DOMContentLoaded', function() {
    sidebarManager = new SidebarManager();
    sidebarManager.init();
});

// Reinitialize on page load (for cached pages)
window.addEventListener('load', function() {
    if (sidebarManager) {
        sidebarManager.init();
    }
});
</script>
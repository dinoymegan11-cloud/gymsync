<?php
declare(strict_types=1);

function render_page_header(string $title, string $subtitle = '', string $actions = ''): void
{
    echo '<div class="page-heading">';
    echo '<div><h1>' . e($title) . '</h1>';
    if ($subtitle !== '') {
        echo '<p>' . e($subtitle) . '</p>';
    }
    echo '</div>';
    if ($actions !== '') {
        echo '<div class="page-actions">' . $actions . '</div>';
    }
    echo '</div>';
}

function render_empty_state(string $iconName, string $title, string $message, string $action = ''): void
{
    echo '<div class="empty-state card"><div class="empty-icon">' . icon($iconName) . '</div>';
    echo '<h3>' . e($title) . '</h3><p>' . e($message) . '</p>' . $action . '</div>';
}

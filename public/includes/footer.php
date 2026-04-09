<?php
declare(strict_types=1);

function renderFooter(): void
{
    echo '</div>';
    echo '<div class="footer"><div class="footer-card">';

    echo '<div style="text-align:center; margin-bottom:14px;">';
    echo '<img src="../assets/logo.png" alt="HIT Media" style="max-height:56px; width:auto; display:inline-block;">';
    echo '</div>';

    echo '<p style="text-align:center;">This application is an independent RuneScape clan event scheduling tool. It is not affiliated with, endorsed by, or connected to Jagex Ltd, RuneScape, or any related intellectual property.</p>';

    echo '</div></div>';
    echo '</div>';
    echo '</body></html>';
}
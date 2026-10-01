<div class="table-container">
    <h3>Storage Zones</h3>
    <p>Click a zone to view detailed information.</p>

    <table class="zone-table">
        <thead>
            <tr>
                <th>Zone</th>
                <th>Type</th>
                <th>Temp Range</th>
                <th>Capacity</th>
                <th>In Use</th>
                <th>Util %</th>
                <th>Alerts</th>
            </tr>
        </thead>
        <tbody>
        <?php if (!empty($zones)): ?>
            <?php foreach ($zones as $z): 
                $util = ($z['CapacityVolume'] > 0) 
                    ? round(($z['InUse'] / $z['CapacityVolume']) * 100) 
                    : 0;

                $rowClass = '';
                if ($z['OpenAlerts'] > 0) $rowClass .= ' row-alert';
                if ($selected_zone && $selected_zone['ZoneCode'] === $z['ZoneCode']) {
                    $rowClass .= ' row-selected';
                }
            ?>
                <tr class="<?php echo trim($rowClass); ?>"
                    onclick="window.location='warehouse-details.php?wid=<?php echo urlencode($wid); ?>&zone=<?php echo urlencode($z['ZoneCode']); ?>#zone-detail'">

                    <td><strong><?php echo htmlspecialchars($z['ZoneCode']); ?></strong></td>

                    <td><?php echo htmlspecialchars(ucwords($z['Classification'])); ?></td>

                    <td>
                        <?php echo number_format($z['MinTemp'], 1); ?> –
                        <?php echo number_format($z['MaxTemp'], 1); ?> °C
                    </td>

                    <td><?php echo number_format($z['CapacityVolume']); ?></td>

                    <td><?php echo number_format($z['InUse']); ?></td>

                    <td class="util-pct"><?php echo $util; ?>%</td>

                    <td>
                        <?php if ($z['OpenAlerts'] > 0): ?>
                            <span class="alert-badge">
                                <?php echo $z['OpenAlerts']; ?> OPEN
                            </span>
                        <?php else: ?>
                            <span class="muted">—</span>
                        <?php endif; ?>
                    </td>

                </tr>
            <?php endforeach; ?>
        <?php else: ?>
            <tr>
                <td colspan="7" class="no-data">No zones found.</td>
            </tr>
        <?php endif; ?>
        </tbody>
    </table>
</div>
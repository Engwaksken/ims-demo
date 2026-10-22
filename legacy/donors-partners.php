<?php

$page_title = 'Donors & Partners Management';
include 'includes/header.php';

check_role(['Administrator', 'Programs Lead','Program Director', 'MEAL Lead']);

$edit_donor = null;
if (isset($_GET['edit_donor'])) {
    $donor_id = intval($_GET['edit_donor']);
    $result = $conn->query("SELECT * FROM donors WHERE donor_id = $donor_id");
    $edit_donor = $result->fetch_assoc();
}

$view_donor = null;
$donor_programs = [];
$donor_projects = [];
if (isset($_GET['view_donor'])) {
    $donor_id = intval($_GET['view_donor']);
    $result = $conn->query("SELECT * FROM donors WHERE donor_id = $donor_id");
    $view_donor = $result->fetch_assoc();
    
    // Fetch donor's programs
    $query = "SELECT * FROM programs WHERE donor_id = $donor_id ORDER BY start_date DESC";
    $result = $conn->query($query);
    while ($row = $result->fetch_assoc()) {
        $donor_programs[] = $row;
    }
    
    // Fetch donor's projects
    $query = "SELECT * FROM projects WHERE donor_id = $donor_id ORDER BY start_date DESC";
    $result = $conn->query($query);
    while ($row = $result->fetch_assoc()) {
        $donor_projects[] = $row;
    }
}

// Get partner for editing
$edit_partner = null;
if (isset($_GET['edit_partner'])) {
    $partner_id = intval($_GET['edit_partner']);
    $result = $conn->query("SELECT * FROM partners WHERE partner_id = $partner_id");
    $edit_partner = $result->fetch_assoc();
}

// Get partner for viewing
$view_partner = null;
$partner_programs = [];
$partner_projects = [];
if (isset($_GET['view_partner'])) {
    $partner_id = intval($_GET['view_partner']);
    $result = $conn->query("SELECT * FROM partners WHERE partner_id = $partner_id");
    $view_partner = $result->fetch_assoc();
    
    // Fetch partner's programs
    $query = "SELECT pr.* FROM programs pr 
              JOIN program_partners pp ON pr.id = pp.program_id 
              WHERE pp.partner_id = $partner_id ORDER BY pr.start_date DESC";
    $result = $conn->query($query);
    while ($row = $result->fetch_assoc()) {
        $partner_programs[] = $row;
    }
    
    // Fetch partner's projects
    $query = "SELECT p.* FROM projects p 
              JOIN project_partners pp ON p.project_id = pp.project_id 
              WHERE pp.partner_id = $partner_id ORDER BY p.start_date DESC";
    $result = $conn->query($query);
    while ($row = $result->fetch_assoc()) {
        $partner_projects[] = $row;
    }
}

// Fetch Donors
$donors = [];
$query = "SELECT d.*, 
    (SELECT COUNT(*) FROM programs pr WHERE pr.donor_id = d.donor_id) as program_count,
    (SELECT COUNT(*) FROM projects p WHERE p.donor_id = d.donor_id) as project_count,
    (SELECT SUM(budget) FROM programs pr WHERE pr.donor_id = d.donor_id) as program_funding,
    (SELECT SUM(budget) FROM projects p WHERE p.donor_id = d.donor_id) as project_funding
    FROM donors d 
    ORDER BY d.donor_name";
$result = $conn->query($query);
while ($row = $result->fetch_assoc()) {
    $donors[] = $row;
}

// Fetch Partners
$partners = [];
$query = "SELECT p.*, 
    (SELECT COUNT(*) FROM program_partners pp WHERE pp.partner_id = p.partner_id) as program_count,
    (SELECT COUNT(*) FROM project_partners pp WHERE pp.partner_id = p.partner_id) as project_count
    FROM partners p 
    ORDER BY p.partner_name";
$result = $conn->query($query);
while ($row = $result->fetch_assoc()) {
    $partners[] = $row;
}
?>

<style>
/* Funding summary: three figures side by side, stacked on phones. */
.dp-funding-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 15px; margin-top: 10px; }
@media (max-width: 768px) { .dp-funding-grid { grid-template-columns: 1fr; } }
</style>

<!-- Statistics -->
<div class="stats-grid reports-stats">
    <div class="stat-card">
        <div class="stat-icon bg-blue"><i class="fas fa-hand-holding-usd" aria-hidden="true"></i></div>
        <div class="stat-info">
            <div class="stat-label">Total Donors</div>
            <div class="stat-value"><?php echo number_format(count($donors)); ?></div>
        </div>
    </div>

    <div class="stat-card">
        <div class="stat-icon bg-green"><i class="fas fa-handshake" aria-hidden="true"></i></div>
        <div class="stat-info">
            <div class="stat-label">Total Partners</div>
            <div class="stat-value"><?php echo number_format(count($partners)); ?></div>
        </div>
    </div>

    <div class="stat-card">
        <div class="stat-icon bg-amber"><i class="fas fa-sitemap" aria-hidden="true"></i></div>
        <div class="stat-info">
            <div class="stat-label">Funded Programs</div>
            <div class="stat-value"><?php echo number_format(array_sum(array_column($donors, 'program_count'))); ?></div>
        </div>
    </div>

    <div class="stat-card">
        <div class="stat-icon bg-teal"><i class="fas fa-project-diagram" aria-hidden="true"></i></div>
        <div class="stat-info">
            <div class="stat-label">Funded Projects</div>
            <div class="stat-value"><?php echo number_format(array_sum(array_column($donors, 'project_count'))); ?></div>
        </div>
    </div>

    <div class="stat-card stat-card-wide">
        <div class="stat-icon bg-purple"><i class="fas fa-dollar-sign" aria-hidden="true"></i></div>
        <div class="stat-info">
            <div class="stat-label">Total Funding</div>
            <div class="stat-value"><span class="stat-unit">UGX</span> <?php echo number_format(array_sum(array_column($donors, 'program_funding')) + array_sum(array_column($donors, 'project_funding'))); ?></div>
        </div>
    </div>
</div>

<!-- Donors Section -->
<div class="card">
    <div class="card-header">
        <h3><i class="fas fa-hand-holding-usd"></i> Donors</h3>
        <button onclick="openModal('addDonorModal')" class="btn btn-primary">
            <i class="fas fa-plus"></i> Add Donor
        </button>
    </div>
    
    <div class="card-body">
        <div class="table-responsive">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Donor Name</th>
                        <th>Contact Person</th>
                        <th>Email</th>
                        <th>Phone</th>
                        <th>Country</th>
                        <th>Programs</th>
                        <th>Projects</th>
                        <th>Total Funding</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($donors)): ?>
                        <tr>
                            <td colspan="9" class="text-center">No donors found</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($donors as $donor): ?>
                            <tr>
                                <td>
                                    <strong><?php echo htmlspecialchars($donor['donor_name']); ?></strong>
                                    <?php if ($donor['website']): ?>
                                        <br><a href="<?php echo htmlspecialchars($donor['website']); ?>" target="_blank" style="font-size: 12px; color: #3498DB;">
                                            <i class="fas fa-external-link-alt"></i> Website
                                        </a>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo htmlspecialchars($donor['contact_person'] ?? 'N/A'); ?></td>
                                <td>
    <?php if (!empty($partner['email'])): ?>
        <a href="mailto:<?php echo htmlspecialchars($partner['email']); ?>"
           class="text-decoration-none"
           style="text-decoration: none;">
            <?php echo htmlspecialchars($partner['email']); ?>
        </a>
    <?php else: ?>
        N/A
    <?php endif; ?>
</td>

<td>
    <?php if (!empty($partner['phone'])): ?>
        <a href="tel:<?php echo htmlspecialchars($partner['phone']); ?>"
           class="text-decoration-none"
           style="text-decoration: none;">
            <?php echo htmlspecialchars($partner['phone']); ?>
        </a>
    <?php else: ?>
        N/A
    <?php endif; ?>
</td>

                                <td><?php echo htmlspecialchars($donor['country'] ?? 'N/A'); ?></td>
                                <td class="text-center">
                                    <span class="badge" style="background: #E67E22; color: white;"><?php echo $donor['program_count']; ?></span>
                                </td>
                                <td class="text-center">
                                    <span class="badge badge-info"><?php echo $donor['project_count']; ?></span>
                                </td>
                                <td><?php echo format_currency(($donor['program_funding'] ?? 0) + ($donor['project_funding'] ?? 0)); ?></td>
                                <td>
                                    <div class="table-actions">
                                        <a href="?view_donor=<?php echo $donor['donor_id']; ?>" 
                                           class="btn btn-info btn-sm" title="View Details">
                                            <i class="fas fa-eye"></i>
                                        </a>
                                        
                                        <a href="?edit_donor=<?php echo $donor['donor_id']; ?>" 
                                           class="btn btn-warning btn-sm" title="Edit">
                                            <i class="fas fa-edit"></i>
                                        </a>
                                        
                                        <?php if (ims_allowed('donors_partners.delete_donors', ['Administrator'], 'Delete donors')): ?>
                                            <button onclick="openDeleteDonorModal(<?php echo $donor['donor_id']; ?>, <?= htmlspecialchars(json_encode((string)($donor['donor_name'])), ENT_QUOTES, 'UTF-8') ?>)" 
                                                    class="btn btn-danger btn-sm" title="Delete">
                                                <i class="fas fa-trash"></i>
                                            </button>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Partners Section -->
<div class="card">
    <div class="card-header">
        <h3><i class="fas fa-handshake"></i> Partners</h3>
        <button onclick="openModal('addPartnerModal')" class="btn btn-success">
            <i class="fas fa-plus"></i> Add Partner
        </button>
    </div>
    
    <div class="card-body">
        <div class="table-responsive">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Partner Name</th>
                        <th>Type</th>
                        <th>Contact Person</th>
                        <th>Email</th>
                        <th>Phone</th>
                        <th>Country</th>
                        <th>Programs</th>
                        <th>Projects</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($partners)): ?>
                        <tr>
                            <td colspan="9" class="text-center">No partners found</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($partners as $partner): ?>
                            <tr>
                                <td><strong><?php echo htmlspecialchars($partner['partner_name']); ?></strong>
                                 <?php if ($partner['website']): ?>
                                        <br><a href="<?php echo htmlspecialchars($partner['website']); ?>" target="_blank" style="font-size: 12px; color: #3498DB;">
                                            <i class="fas fa-external-link-alt"></i> Website
                                        </a>
                                    <?php endif; ?>
                                
                                </td>
                                <td>
                                    <?php
                                    $type_badges = [
                                        'Implementing' => 'success',
                                        'Technical' => 'info',
                                        'Financial' => 'warning',
                                        'Other' => 'secondary'
                                    ];
                                    $badge = $type_badges[$partner['partner_type']] ?? 'secondary';
                                    ?>
                                    <span class="badge badge-<?php echo $badge; ?>">
                                        <?php echo $partner['partner_type']; ?>
                                    </span>
                                   <?php if ($partner['letter']): ?>
                                        <br><a href="<?php echo htmlspecialchars(ims_upload_url($partner['letter'])); ?>" target="_blank" style="font-size: 12px; color: #3498DB;">
                                            <i class="fas fa-file"></i> Letter
                                        </a>
                                    <?php endif; ?>
                                
                                </td>
                                <td><?php echo htmlspecialchars($partner['contact_person'] ?? 'N/A'); ?></td>
                               <td>
    <?php if (!empty($partner['email'])): ?>
        <a href="mailto:<?php echo htmlspecialchars($partner['email']); ?>"
           class="text-decoration-none">
            <?php echo htmlspecialchars($partner['email']); ?>
        </a>
    <?php else: ?>
        N/A
    <?php endif; ?>
</td>

<td>
    <?php if (!empty($partner['phone'])): ?>
        <a href="tel:<?php echo htmlspecialchars($partner['phone']); ?>"
           class="text-decoration-none">
            <?php echo htmlspecialchars($partner['phone']); ?>
        </a>
    <?php else: ?>
        N/A
    <?php endif; ?>
</td>

                                <td><?php echo htmlspecialchars($partner['country'] ?? 'N/A'); ?></td>
                                <td class="text-center">
                                    <span class="badge" style="background: #E67E22; color: white;"><?php echo $partner['program_count']; ?></span>
                                </td>
                                <td class="text-center">
                                    <span class="badge badge-info"><?php echo $partner['project_count']; ?></span>
                                </td>
                                <td>
                                    <div class="table-actions">
                                        <a href="?view_partner=<?php echo $partner['partner_id']; ?>" 
                                           class="btn btn-info btn-sm" title="View Details">
                                            <i class="fas fa-eye"></i>
                                        </a>
                                        
                                        <a href="?edit_partner=<?php echo $partner['partner_id']; ?>" 
                                           class="btn btn-warning btn-sm" title="Edit">
                                            <i class="fas fa-edit"></i>
                                        </a>
                                        
                                        <?php if (ims_allowed('donors_partners.delete_partners', ['Administrator'], 'Delete partners')): ?>
                                            <button onclick="openDeletePartnerModal(<?php echo $partner['partner_id']; ?>, <?= htmlspecialchars(json_encode((string)($partner['partner_name'])), ENT_QUOTES, 'UTF-8') ?>)" 
                                                    class="btn btn-danger btn-sm" title="Delete">
                                                <i class="fas fa-trash"></i>
                                            </button>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Add Donor Modal -->
<div id="addDonorModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h3>Add New Donor</h3>
            <span class="close" onclick="closeModal('addDonorModal')">&times;</span>
        </div>
        <div class="modal-body">
            <form method="POST" action="includes/donors-partners-process.php" enctype="multipart/form-data">
                <div class="form-group">
                    <label for="donor_name" class="required">Donor Name</label>
                    <input type="text" id="donor_name" name="donor_name" class="form-control" required>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label for="contact_person">Contact Person</label>
                        <input type="text" id="contact_person" name="contact_person" class="form-control">
                    </div>
                    
                    <div class="form-group">
                        <label for="email">Email</label>
                        <input type="email" id="email" name="email" class="form-control">
                    </div>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label for="phone">Phone</label>
                        <input type="tel" id="phone" name="phone" class="form-control">
                    </div>
                    
                    <div class="form-group">
                        <label for="country">Country</label>
                        <input type="text" id="country" name="country" class="form-control">
                    </div>
                </div>
                
                <div class="form-group">
                    <label for="website">Website</label>
                    <input type="url" id="website" name="website" class="form-control" placeholder="https://">
                </div>
                
                <div class="form-group">
                    <label for="address">Address</label>
                    <textarea id="address" name="address" class="form-control" rows="3"></textarea>
                </div>
                
                <div class="modal-footer">
                    <button type="button" onclick="closeModal('addDonorModal')" class="btn btn-secondary">Cancel</button>
                    <button type="submit" name="add_donor" class="btn btn-success">
                        <i class="fas fa-save"></i> Add Donor
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Edit Donor Modal -->
<div id="editDonorModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h3>Edit Donor</h3>
            <span class="close" onclick="closeModal('editDonorModal')">&times;</span>
        </div>
        <div class="modal-body">
            <?php if ($edit_donor): ?>
                <form method="POST" action="includes/donors-partners-process.php">
                    <input type="hidden" name="donor_id" value="<?php echo $edit_donor['donor_id']; ?>">
                    
                    <div class="form-group">
                        <label for="edit_donor_name" class="required">Donor Name</label>
                        <input type="text" id="edit_donor_name" name="donor_name" class="form-control" 
                               value="<?php echo htmlspecialchars($edit_donor['donor_name']); ?>" required>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label for="edit_contact_person">Contact Person</label>
                            <input type="text" id="edit_contact_person" name="contact_person" class="form-control"
                                   value="<?php echo htmlspecialchars($edit_donor['contact_person']); ?>">
                        </div>
                        
                        <div class="form-group">
                            <label for="edit_email">Email</label>
                            <input type="email" id="edit_email" name="email" class="form-control"
                                   value="<?php echo htmlspecialchars($edit_donor['email']); ?>">
                        </div>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label for="edit_phone">Phone</label>
                            <input type="tel" id="edit_phone" name="phone" class="form-control"
                                   value="<?php echo htmlspecialchars($edit_donor['phone']); ?>">
                        </div>
                        
                        <div class="form-group">
                            <label for="edit_country">Country</label>
                            <input type="text" id="edit_country" name="country" class="form-control"
                                   value="<?php echo htmlspecialchars($edit_donor['country']); ?>">
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label for="edit_website">Website</label>
                        <input type="url" id="edit_website" name="website" class="form-control" 
                               value="<?php echo htmlspecialchars($edit_donor['website']); ?>">
                    </div>
                    
                    <div class="form-group">
                        <label for="edit_address">Address</label>
                        <textarea id="edit_address" name="address" class="form-control" rows="3"><?php echo htmlspecialchars($edit_donor['address']); ?></textarea>
                    </div>
                    
                    <div class="modal-footer">
                        <button type="button" onclick="closeModal('editDonorModal')" class="btn btn-secondary">Cancel</button>
                        <button type="submit" name="edit_donor" class="btn btn-success">
                            <i class="fas fa-save"></i> Update Donor
                        </button>
                    </div>
                </form>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- View Donor Modal -->
<div id="viewDonorModal" class="modal">
    <div class="modal-content" style="max-width: 900px;">
        <div class="modal-header">
            <h3>Donor Details</h3>
            <span class="close" onclick="closeModal('viewDonorModal')">&times;</span>
        </div>
        <div class="modal-body">
            <?php if ($view_donor): ?>
                <div style="padding: 20px; background: #f8f9fa; border-radius: 8px; margin-bottom: 20px;">
                    <h2 style="margin: 0 0 15px 0; color: var(--primary-color);">
                        <?php echo htmlspecialchars($view_donor['donor_name']); ?>
                    </h2>
                    
                    <div style="display: grid; grid-template-columns: repeat(2, 1fr); gap: 15px;">
                        <?php if ($view_donor['contact_person']): ?>
                            <div>
                                <strong>Contact Person:</strong><br>
                                <?php echo htmlspecialchars($view_donor['contact_person']); ?>
                            </div>
                        <?php endif; ?>
                        
                        <?php if ($view_donor['email']): ?>
                            <div>
                                <strong>Email:</strong><br>
                                <a href="mailto:<?php echo htmlspecialchars($view_donor['email']); ?>">
                                    <?php echo htmlspecialchars($view_donor['email']); ?>
                                </a>
                            </div>
                        <?php endif; ?>
                        
                        <?php if ($view_donor['phone']): ?>
                            <div>
                                <strong>Phone:</strong><br>
                                <a href="tel:<?php echo htmlspecialchars($view_donor['phone']); ?>">
                                    <?php echo htmlspecialchars($view_donor['phone']); ?>
                                </a>
                            </div>
                        <?php endif; ?>
                        
                        <?php if ($view_donor['country']): ?>
                            <div>
                                <strong>Country:</strong><br>
                                <?php echo htmlspecialchars($view_donor['country']); ?>
                            </div>
                        <?php endif; ?>
                        
                        <?php if ($view_donor['website']): ?>
                            <div style="grid-column: 1 / -1;">
                                <strong>Website:</strong><br>
                                <a href="<?php echo htmlspecialchars($view_donor['website']); ?>" target="_blank">
                                    <?php echo htmlspecialchars($view_donor['website']); ?> <i class="fas fa-external-link-alt"></i>
                                </a>
                            </div>
                        <?php endif; ?>
                        
                        <?php if ($view_donor['address']): ?>
                            <div style="grid-column: 1 / -1;">
                                <strong>Address:</strong><br>
                                <?php echo nl2br(htmlspecialchars($view_donor['address'])); ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
                
                <!-- Programs Section -->
                <h4 style="margin: 20px 0 10px 0;">
                    <i class="fas fa-sitemap" style="color: #E67E22;"></i> Funded Programs (<?php echo count($donor_programs); ?>)
                </h4>
                
                <?php if (empty($donor_programs)): ?>
                    <p style="color: #7f8c8d; text-align: center; padding: 10px; background: #f8f9fa; border-radius: 5px;">No programs found for this donor.</p>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>Program Code</th>
                                    <th>Program Name</th>
                                    <th>Budget</th>
                                    <th>Duration</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($donor_programs as $program): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($program['program_code']); ?></td>
                                        <td><?php echo htmlspecialchars($program['program_name']); ?></td>
                                        <td><?php echo format_currency($program['budget'] ?? 0); ?></td>
                                        <td>
                                            <?php echo date('M Y', strtotime($program['start_date'])); ?> - 
                                            <?php echo $program['end_date'] ? date('M Y', strtotime($program['end_date'])) : 'Ongoing'; ?>
                                        </td>
                                        <td>
                                            <span class="badge badge-<?php 
                                                echo $program['status'] == 'Active' ? 'success' : 
                                                    ($program['status'] == 'Completed' ? 'info' : 'secondary'); 
                                            ?>">
                                                <?php echo $program['status']; ?>
                                            </span>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
                
                <!-- Projects Section -->
                <h4 style="margin: 20px 0 10px 0;">
                    <i class="fas fa-project-diagram" style="color: #3498DB;"></i> Funded Projects (<?php echo count($donor_projects); ?>)
                </h4>
                
                <?php if (empty($donor_projects)): ?>
                    <p style="color: #7f8c8d; text-align: center; padding: 10px; background: #f8f9fa; border-radius: 5px;">No projects found for this donor.</p>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>Project Code</th>
                                    <th>Project Name</th>
                                    <th>Budget</th>
                                    <th>Duration</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($donor_projects as $project): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($project['project_code']); ?></td>
                                        <td><?php echo htmlspecialchars($project['project_name']); ?></td>
                                        <td><?php echo format_currency($project['budget']); ?></td>
                                        <td>
                                            <?php echo date('M Y', strtotime($project['start_date'])); ?> - 
                                            <?php echo date('M Y', strtotime($project['end_date'])); ?>
                                        </td>
                                        <td>
                                            <?php
                                            $badge_colors = [
                                                'Ongoing' => 'success',
                                                'Completed' => 'info',
                                                'Pending' => 'warning',
                                                'Cancelled' => 'danger'
                                            ];
                                            $badge = $badge_colors[$project['status']] ?? 'secondary';
                                            ?>
                                            <span class="badge badge-<?php echo $badge; ?>">
                                                <?php echo $project['status']; ?>
                                            </span>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
                
                <!-- Summary -->
                <?php if (!empty($donor_programs) || !empty($donor_projects)): ?>
                    <div style="margin-top: 20px; padding: 15px; background: #e8f5e9; border-radius: 8px; border-left: 4px solid #2ECC71;">
                        <strong>Funding Summary:</strong>
                        <div class="dp-funding-grid">
                            <div>
                                <div style="font-size: 12px; color: #7f8c8d;">Total Programs</div>
                                <div style="font-size: 24px; font-weight: bold; color: #E67E22;"><?php echo count($donor_programs); ?></div>
                            </div>
                            <div>
                                <div style="font-size: 12px; color: #7f8c8d;">Total Projects</div>
                                <div style="font-size: 24px; font-weight: bold; color: #3498DB;"><?php echo count($donor_projects); ?></div>
                            </div>
                            <div>
                                <div style="font-size: 12px; color: #7f8c8d;">Total Funding</div>
                                <div style="font-size: 24px; font-weight: bold; color: #2ECC71;">
                                    <?php 
                                    $total_funding = array_sum(array_column($donor_programs, 'budget')) + array_sum(array_column($donor_projects, 'budget'));
                                    echo format_currency($total_funding); 
                                    ?>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>
</div>
<!-- Add Partner Modal -->
<div id="addPartnerModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h3>Add New Partner</h3>
            <span class="close" onclick="closeModal('addPartnerModal')">&times;</span>
        </div>

        <div class="modal-body">
            <form method="POST" action="includes/donors-partners-process.php" enctype="multipart/form-data">

                <div class="form-group">
                    <label for="partner_name" class="required">Partner Name</label>
                    <input type="text" id="partner_name" name="partner_name" class="form-control" required>
                </div>

                <div class="form-group">
                    <label for="partner_type" class="required">Partner Type</label>
                    <select id="partner_type" name="partner_type" class="form-control" required>
                        <option value="">Select Type</option>
                        <option value="Implementing">Implementing</option>
                        <option value="Technical">Technical</option>
                        <option value="Financial">Financial</option>
                        <option value="Other">Other</option>
                    </select>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label for="contact_person">Contact Person</label>
                        <input type="text" id="contact_person" name="contact_person" class="form-control">
                    </div>

                    <div class="form-group">
                        <label for="partner_email">Email</label>
                        <input type="email" id="partner_email" name="email" class="form-control">
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label for="partner_phone">Phone</label>
                        <input type="tel" id="partner_phone" name="phone" class="form-control">
                    </div>

                    <div class="form-group">
                        <label for="partner_country">Country</label>
                        <input type="text" id="partner_country" name="country" class="form-control">
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label for="website_partner">Website</label>
                        <input type="url" id="website_partner" name="website_partner" class="form-control" placeholder="https://">
                    </div>

                    <div class="form-group">
                        <label for="recommend_letter">Recommendation Letter</label>
                        <input type="file" id="recommend_letter" name="recommend_letter"
                               class="form-control" accept=".pdf,.doc,.docx">
                    </div>
                </div>

                <div class="form-group">
                    <label for="partner_address">Address</label>
                    <textarea id="partner_address" name="address" class="form-control" rows="3"></textarea>
                </div>

                <div class="modal-footer">
                    <button type="button" onclick="closeModal('addPartnerModal')" class="btn btn-secondary">Cancel</button>
                    <button type="submit" name="add_partner" class="btn btn-success">
                        <i class="fas fa-save"></i> Add Partner
                    </button>
                </div>

            </form>
        </div>
    </div>
</div>

<!-- Edit Partner Modal -->
<div id="editPartnerModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h3>Edit Partner</h3>
            <span class="close" onclick="closeModal('editPartnerModal')">&times;</span>
        </div>

        <div class="modal-body">
        <?php if ($edit_partner): ?>
            <form method="POST" action="includes/donors-partners-process.php" enctype="multipart/form-data">
                <input type="hidden" name="partner_id" value="<?php echo (int)$edit_partner['partner_id']; ?>">

                <div class="form-group">
                    <label for="edit_partner_name" class="required">Partner Name</label>
                    <input type="text" id="edit_partner_name" name="partner_name" class="form-control"
                           value="<?php echo htmlspecialchars($edit_partner['partner_name']); ?>" required>
                </div>

                <div class="form-group">
                    <label for="edit_partner_type" class="required">Partner Type</label>
                    <select id="edit_partner_type" name="partner_type" class="form-control" required>
                        <?php foreach (['Implementing','Technical','Financial','Other'] as $type): ?>
                            <option value="<?php echo $type; ?>"
                                <?php echo $edit_partner['partner_type'] === $type ? 'selected' : ''; ?>>
                                <?php echo $type; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label for="edit_contact_person">Contact Person</label>
                        <input type="text" id="edit_contact_person" name="contact_person" class="form-control"
                               value="<?php echo htmlspecialchars($edit_partner['contact_person']); ?>">
                    </div>

                    <div class="form-group">
                        <label for="edit_partner_email">Email</label>
                        <input type="email" id="edit_partner_email" name="email" class="form-control"
                               value="<?php echo htmlspecialchars($edit_partner['email']); ?>">
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label for="edit_partner_phone">Phone</label>
                        <input type="tel" id="edit_partner_phone" name="phone" class="form-control"
                               value="<?php echo htmlspecialchars($edit_partner['phone']); ?>">
                    </div>

                    <div class="form-group">
                        <label for="edit_partner_country">Country</label>
                        <input type="text" id="edit_partner_country" name="country" class="form-control"
                               value="<?php echo htmlspecialchars($edit_partner['country']); ?>">
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label for="edit_partner_website">Website</label>
                        <input type="url" id="edit_partner_website" name="website_partner" class="form-control"
                               value="<?php echo htmlspecialchars($edit_partner['website']); ?>">
                    </div>

                    <div class="form-group">
                        <label for="edit_partner_letter">Recommendation Letter</label>
                        <input type="file" id="edit_partner_letter" name="recommend_letter"
                               class="form-control" accept=".pdf,.doc,.docx">
                        <?php if (!empty($edit_partner['letter'])): ?>
                            <small class="text-muted">
                                Current:
                                <a href="<?php echo htmlspecialchars(ims_upload_url($edit_partner['letter'])); ?>" target="_blank">
                                    View file
                                </a>
                            </small>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="form-group">
                    <label for="edit_partner_address">Address</label>
                    <textarea id="edit_partner_address" name="address" class="form-control" rows="3">
<?php echo htmlspecialchars($edit_partner['address']); ?>
                    </textarea>
                </div>

                <div class="modal-footer">
                    <button type="button" onclick="closeModal('editPartnerModal')" class="btn btn-secondary">Cancel</button>
                    <button type="submit" name="edit_partner" class="btn btn-success">
                        <i class="fas fa-save"></i> Update Partner
                    </button>
                </div>
            </form>
        <?php endif; ?>
        </div>
    </div>
</div>


<!-- View Partner Modal -->
<div id="viewPartnerModal" class="modal">
    <div class="modal-content" style="max-width: 900px;">
        <div class="modal-header">
            <h3>Partner Details</h3>
            <span class="close" onclick="closeModal('viewPartnerModal')">&times;</span>
        </div>
        <div class="modal-body">
            <?php if ($view_partner): ?>
                <div style="padding: 20px; background: #f8f9fa; border-radius: 8px; margin-bottom: 20px;">
                    <h2 style="margin: 0 0 15px 0; color: var(--primary-color);">
                        <?php echo htmlspecialchars($view_partner['partner_name']); ?>
                    </h2>
                    
                    <div style="display: grid; grid-template-columns: repeat(2, 1fr); gap: 15px;">
                        <div>
                            <strong>Partner Type:</strong><br>
                            <?php
                            $type_badges = [
                                'Implementing' => 'success',
                                'Technical' => 'info',
                                'Financial' => 'warning',
                                'Other' => 'secondary'
                            ];
                            $badge = $type_badges[$view_partner['partner_type']] ?? 'secondary';
                            ?>
                            <span class="badge badge-<?php echo $badge; ?>">
                                <?php echo $view_partner['partner_type']; ?>
                            </span>
                        </div>
                        
                        <?php if ($view_partner['contact_person']): ?>
                            <div>
                                <strong>Contact Person:</strong><br>
                                <?php echo htmlspecialchars($view_partner['contact_person']); ?>
                            </div>
                        <?php endif; ?>
                        
                        <?php if ($view_partner['email']): ?>
                            <div>
                                <strong>Email:</strong><br>
                                <a href="mailto:<?php echo htmlspecialchars($view_partner['email']); ?>">
                                    <?php echo htmlspecialchars($view_partner['email']); ?>
                                </a>
                            </div>
                        <?php endif; ?>
                        
                        <?php if ($view_partner['phone']): ?>
                            <div>
                                <strong>Phone:</strong><br>
                                <a href="tel:<?php echo htmlspecialchars($view_partner['phone']); ?>">
                                    <?php echo htmlspecialchars($view_partner['phone']); ?>
                                </a>
                            </div>
                        <?php endif; ?>
                        
                        <?php if ($view_partner['country']): ?>
                            <div>
                                <strong>Country:</strong><br>
                                <?php echo htmlspecialchars($view_partner['country']); ?>
                            </div>
                        <?php endif; ?>
                        
                         
                        <?php if ($view_partner['website']): ?>
                            <div style="grid-column: 1 / -1;">
                                <strong>Website:</strong><br>
                                <a href="<?php echo htmlspecialchars($view_partner['website']); ?>" target="_blank">
                                    <?php echo htmlspecialchars($view_partner['website']); ?> <i class="fas fa-external-link-alt"></i>
                                </a>
                            </div>
                        <?php endif; ?>
                        
                       <?php if ($view_partner['letter']): ?>
<div>
    <strong>Recommendation Letter:</strong><br>
    <a href="<?php echo htmlspecialchars(ims_upload_url($view_partner['letter'])); ?>" target="_blank">
        View / Download Letter
    </a>
</div>
<?php endif; ?>

                        
                        
                        <?php if ($view_partner['address']): ?>
                            <div style="grid-column: 1 / -1;">
                                <strong>Address:</strong><br>
                                <?php echo nl2br(htmlspecialchars($view_partner['address'])); ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
                
                <!-- Programs Section -->
                <h4 style="margin: 20px 0 10px 0;">
                    <i class="fas fa-sitemap" style="color: #E67E22;"></i> Collaborative Programs (<?php echo count($partner_programs); ?>)
                </h4>
                
                <?php if (empty($partner_programs)): ?>
                    <p style="color: #7f8c8d; text-align: center; padding: 10px; background: #f8f9fa; border-radius: 5px;">No programs found for this partner.</p>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>Program Code</th>
                                    <th>Program Name</th>
                                    <th>Budget</th>
                                    <th>Duration</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($partner_programs as $program): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($program['program_code']); ?></td>
                                        <td><?php echo htmlspecialchars($program['program_name']); ?></td>
                                        <td><?php echo format_currency($program['budget'] ?? 0); ?></td>
                                        <td>
                                            <?php echo date('M Y', strtotime($program['start_date'])); ?> - 
                                            <?php echo $program['end_date'] ? date('M Y', strtotime($program['end_date'])) : 'Ongoing'; ?>
                                        </td>
                                        <td>
                                            <span class="badge badge-<?php 
                                                echo $program['status'] == 'Active' ? 'success' : 
                                                    ($program['status'] == 'Completed' ? 'info' : 'secondary'); 
                                            ?>">
                                                <?php echo $program['status']; ?>
                                            </span>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
                
                <!-- Projects Section -->
                <h4 style="margin: 20px 0 10px 0;">
                    <i class="fas fa-project-diagram" style="color: #3498DB;"></i> Collaborative Projects (<?php echo count($partner_projects); ?>)
                </h4>
                
                <?php if (empty($partner_projects)): ?>
                    <p style="color: #7f8c8d; text-align: center; padding: 10px; background: #f8f9fa; border-radius: 5px;">No projects found for this partner.</p>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>Project Code</th>
                                    <th>Project Name</th>
                                    <th>Budget</th>
                                    <th>Duration</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($partner_projects as $project): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($project['project_code']); ?></td>
                                        <td><?php echo htmlspecialchars($project['project_name']); ?></td>
                                        <td><?php echo format_currency($project['budget']); ?></td>
                                        <td>
                                            <?php echo date('M Y', strtotime($project['start_date'])); ?> - 
                                            <?php echo date('M Y', strtotime($project['end_date'])); ?>
                                        </td>
                                        <td>
                                            <?php
                                            $badge_colors = [
                                                'Ongoing' => 'success',
                                                'Completed' => 'info',
                                                'Pending' => 'warning',
                                                'Cancelled' => 'danger'
                                            ];
                                            $badge = $badge_colors[$project['status']] ?? 'secondary';
                                            ?>
                                            <span class="badge badge-<?php echo $badge; ?>">
                                                <?php echo $project['status']; ?>
                                            </span>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
                
                <!-- Summary -->
                <?php if (!empty($partner_programs) || !empty($partner_projects)): ?>
                    <div style="margin-top: 20px; padding: 15px; background: #e3f2fd; border-radius: 8px; border-left: 4px solid #2196F3;">
                        <strong>Collaboration Summary:</strong>
                        <div style="display: grid; grid-template-columns: repeat(2, 1fr); gap: 15px; margin-top: 10px;">
                            <div>
                                <div style="font-size: 12px; color: #7f8c8d;">Total Programs</div>
                                <div style="font-size: 24px; font-weight: bold; color: #E67E22;"><?php echo count($partner_programs); ?></div>
                            </div>
                            <div>
                                <div style="font-size: 12px; color: #7f8c8d;">Total Projects</div>
                                <div style="font-size: 24px; font-weight: bold; color: #3498DB;"><?php echo count($partner_projects); ?></div>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Delete Donor Modal -->
<div id="deleteDonorModal" class="modal">
    <div class="modal-content" style="max-width: 500px;">
        <div class="modal-header">
            <h3 style="color: #E74C3C;"><i class="fas fa-exclamation-triangle"></i> Confirm Deletion</h3>
            <span class="close" onclick="closeModal('deleteDonorModal')">&times;</span>
        </div>
        <div class="modal-body">
            <p style="font-size: 16px; margin-bottom: 20px;">
                Are you sure you want to delete <strong id="deleteDonorName"></strong>?
            </p>
            <div class="alert alert-warning">
                <i class="fas fa-info-circle"></i>
                This action cannot be undone. Ensure no programs or projects are associated with this donor.
            </div>
            
            <form method="POST" action="includes/donors-partners-process.php">
                <input type="hidden" name="donor_id" id="deleteDonorId">
                <div class="modal-footer">
                    <button type="button" onclick="closeModal('deleteDonorModal')" class="btn btn-secondary">Cancel</button>
                    <button type="submit" name="delete_donor" class="btn btn-danger">
                        <i class="fas fa-trash"></i> Yes, Delete
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Delete Partner Modal -->
<div id="deletePartnerModal" class="modal">
    <div class="modal-content" style="max-width: 500px;">
        <div class="modal-header">
            <h3 style="color: #E74C3C;"><i class="fas fa-exclamation-triangle"></i> Confirm Deletion</h3>
            <span class="close" onclick="closeModal('deletePartnerModal')">&times;</span>
        </div>
        <div class="modal-body">
            <p style="font-size: 16px; margin-bottom: 20px;">
                Are you sure you want to delete <strong id="deletePartnerName"></strong>?
            </p>
            <div class="alert alert-warning">
                <i class="fas fa-info-circle"></i>
                This action cannot be undone. Ensure no programs or projects are associated with this partner.
            </div>
            
            <form method="POST" action="includes/donors-partners-process.php">
                <input type="hidden" name="partner_id" id="deletePartnerId">
                <div class="modal-footer">
                    <button type="button" onclick="closeModal('deletePartnerModal')" class="btn btn-secondary">Cancel</button>
                    <button type="submit" name="delete_partner" class="btn btn-danger">
                        <i class="fas fa-trash"></i> Yes, Delete
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function openDeleteDonorModal(donorId, donorName) {
    document.getElementById('deleteDonorId').value = donorId;
    document.getElementById('deleteDonorName').textContent = donorName;
    openModal('deleteDonorModal');
}

function openDeletePartnerModal(partnerId, partnerName) {
    document.getElementById('deletePartnerId').value = partnerId;
    document.getElementById('deletePartnerName').textContent = partnerName;
    openModal('deletePartnerModal');
}

// Auto-open modals if editing or viewing
<?php if ($edit_donor): ?>
    window.addEventListener('DOMContentLoaded', function() {
        openModal('editDonorModal');
    });
<?php endif; ?>

<?php if ($view_donor): ?>
    window.addEventListener('DOMContentLoaded', function() {
        openModal('viewDonorModal');
    });
<?php endif; ?>

<?php if ($edit_partner): ?>
    window.addEventListener('DOMContentLoaded', function() {
        openModal('editPartnerModal');
    });
<?php endif; ?>

<?php if ($view_partner): ?>
    window.addEventListener('DOMContentLoaded', function() {
        openModal('viewPartnerModal');
    });
<?php endif; ?>
</script>

<?php include 'includes/footer.php'; ?>
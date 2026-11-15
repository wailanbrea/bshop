export interface User { id: number; name: string; email: string }
export interface Business { id: number; name: string; timezone: string; default_currency: string }
export interface Store { id: number; name: string; code: string; address: string | null; phone: string | null; is_active: boolean }
export interface Warehouse { id: number; store_id: number; name: string; is_default: boolean; is_active: boolean }
export interface Session { user: User; business: Business; roles: string[]; permissions: string[] }
export interface Workspace { business: Business; stores: Store[]; permissions: string[] }
export interface TeamMember { id: number; user_id: number; name: string; email: string; status: 'active' | 'suspended'; user_active: boolean; role_ids: number[]; role_labels: string[]; store_ids: number[]; can_manage: boolean }
export interface TeamRole { id: number; code: string; label: string }
export interface TeamInvitation { id: number; email: string; role_id: number; expires_at: string; status: string }
export interface Team { members: TeamMember[]; roles: TeamRole[]; stores: Store[]; invitations: TeamInvitation[] }

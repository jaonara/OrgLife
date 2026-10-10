import { useEffect, useMemo, useState } from 'react'
import './App.css'
import Auth from './auth'
import api from './api'

function readSavedUser() {
  try {
    const savedUser = localStorage.getItem('orglife_user')
    const savedToken = localStorage.getItem('orglife_token')

    if (savedUser && savedToken) {
      api.defaults.headers.common.Authorization = `Bearer ${savedToken}`
      return JSON.parse(savedUser)
    }
  } catch {
    localStorage.removeItem('orglife_user')
    localStorage.removeItem('orglife_token')
  }

  return null
}

function errorMessage(error) {
  if (!error.response) {
    return `Cannot reach the OrgLife API at ${api.defaults.baseURL}. Start Laravel with "php artisan serve --host 127.0.0.1 --port 8000".`
  }

  const errors = error.response.data?.errors
  return (
    (errors && Object.values(errors)[0]?.[0]) ||
    error.response.data?.message ||
    'The request failed. Please try again.'
  )
}

function App() {
  const [user, setUser] = useState(readSavedUser)
  const [activePage, setActivePage] = useState('Discover')
  const [organizations, setOrganizations] = useState([])
  const [colleges, setColleges] = useState([])
  const [requests, setRequests] = useState([])
  const [myOrganizations, setMyOrganizations] = useState([])
  const [officerRequests, setOfficerRequests] = useState([])
  const [selectedOrganization, setSelectedOrganization] = useState(null)
  const [members, setMembers] = useState([])
  const [search, setSearch] = useState('')
  const [college, setCollege] = useState('All Colleges')
  const [type, setType] = useState('All Types')
  const [openOnly, setOpenOnly] = useState(false)
  const [officerOrganizationId, setOfficerOrganizationId] = useState('all')
  const [loading, setLoading] = useState(false)
  const [error, setError] = useState('')
  const [notice, setNotice] = useState('')

  async function loadOrganizations() {
    const response = await api.get('/organizations')
    setOrganizations(response.data.data)
    setColleges(response.data.meta.colleges)
  }

  async function loadRequests() {
    const response = await api.get('/membership-requests')
    setRequests(response.data.data)
  }

  async function loadMyOrganizations() {
    const response = await api.get('/my-organizations')
    setMyOrganizations(response.data.data)
  }

  async function loadOfficerRequests(organizationId = officerOrganizationId) {
    const params = organizationId === 'all' ? {} : { organization_id: organizationId }
    const response = await api.get('/officer/requests', { params })
    setOfficerRequests(response.data.data)
  }

  useEffect(() => {
    if (!user) return

    let cancelled = false
    Promise.all([
      api.get('/organizations'),
      api.get('/membership-requests'),
      api.get('/my-organizations'),
    ])
      .then(([orgResponse, requestResponse, membershipResponse]) => {
        if (cancelled) return
        setOrganizations(orgResponse.data.data)
        setColleges(orgResponse.data.meta.colleges)
        setRequests(requestResponse.data.data)
        setMyOrganizations(membershipResponse.data.data)
        setError('')
      })
      .catch((loadError) => {
        if (cancelled) return
        if (loadError.response?.status === 401) {
          handleLogout()
          return
        }
        setError(errorMessage(loadError))
      })
      .finally(() => {
        if (!cancelled) setLoading(false)
      })

    return () => {
      cancelled = true
    }
  }, [user])

  function handleLogin(userData, token) {
    localStorage.setItem('orglife_token', token)
    localStorage.setItem('orglife_user', JSON.stringify(userData))
    api.defaults.headers.common.Authorization = `Bearer ${token}`
    setError('')
    setNotice('')
    setUser(userData)
  }

  async function handleLogout() {
    try {
      if (localStorage.getItem('orglife_token')) await api.post('/logout')
    } catch (logoutError) {
      console.error('Logout request failed:', logoutError)
    } finally {
      localStorage.removeItem('orglife_token')
      localStorage.removeItem('orglife_user')
      delete api.defaults.headers.common.Authorization
      setUser(null)
      setActivePage('Discover')
      setSelectedOrganization(null)
      setRequests([])
      setMyOrganizations([])
      setOfficerRequests([])
    }
  }

  async function runAction(action, successMessage) {
    setError('')
    setNotice('')
    setLoading(true)
    try {
      await action()
      if (successMessage) setNotice(successMessage)
    } catch (actionError) {
      if (actionError.response?.status === 401) {
        await handleLogout()
      } else {
        setError(errorMessage(actionError))
      }
    } finally {
      setLoading(false)
    }
  }

  async function openOrganization(organizationId) {
    await runAction(async () => {
      const response = await api.get(`/organizations/${organizationId}`)
      setSelectedOrganization(response.data.data)
      setMembers([])
      setActivePage('Organization')
    })
  }

  async function viewMembers() {
    if (!selectedOrganization) return
    await runAction(async () => {
      const response = await api.get(
        `/organizations/${selectedOrganization.id}/members`,
      )
      setMembers(response.data.data)
    })
  }

  async function requestToJoin(organizationId) {
    await runAction(async () => {
      await api.post(`/organizations/${organizationId}/membership-requests`)
      await Promise.all([loadRequests(), loadOrganizations()])
      const response = await api.get(`/organizations/${organizationId}`)
      setSelectedOrganization(response.data.data)
    }, 'Your request to join has been sent.')
  }

  async function withdrawRequest(organizationId) {
    await runAction(async () => {
      await api.delete(`/organizations/${organizationId}/membership-requests`)
      await Promise.all([loadRequests(), loadOrganizations()])
      if (selectedOrganization?.id === organizationId) {
        const response = await api.get(`/organizations/${organizationId}`)
        setSelectedOrganization(response.data.data)
      }
    }, 'Your pending request has been withdrawn.')
  }

  async function openOfficerScreen() {
    await runAction(async () => {
      await loadOfficerRequests('all')
      setOfficerOrganizationId('all')
      setActivePage('Officer Requests')
    })
  }

  async function decideRequest(requestId, status) {
    await runAction(async () => {
      await api.patch(`/officer/requests/${requestId}`, { status })
      await Promise.all([loadOfficerRequests(), loadRequests(), loadMyOrganizations()])
    }, `Request ${status}.`)
  }

  const filteredOrganizations = useMemo(() => {
    const term = search.trim().toLowerCase()
    return organizations.filter((organization) => {
      const matchesSearch =
        !term ||
        organization.name.toLowerCase().includes(term) ||
        organization.cause.toLowerCase().includes(term)
      const matchesCollege =
        college === 'All Colleges' ||
        organization.college === college ||
        organization.college === 'All Colleges'
      const matchesType = type === 'All Types' || organization.category === type
      return matchesSearch && matchesCollege && matchesType && (!openOnly || organization.open)
    })
  }, [organizations, search, college, type, openOnly])

  const typeOptions = useMemo(
    () => [...new Set(organizations.map((organization) => organization.category))].sort(),
    [organizations],
  )
  const officerOrganizations = myOrganizations.filter((organization) =>
    ['President', 'Officer'].includes(organization.role),
  )

  if (!user) return <Auth onLogin={handleLogin} />

  function navTo(page) {
    setError('')
    setNotice('')
    setSelectedOrganization(null)
    setActivePage(page)
  }

  return (
    <div className="app">
      <aside className="sidebar">
        <div className="brand">
          <div className="brand-icon">O</div>
          <div><h2>OrgLife</h2><p>Campus community</p></div>
        </div>
        <p className="nav-label">MENU</p>
        {['Dashboard', 'Discover', 'My Organizations', 'My Requests'].map((page) => (
          <button key={page} className={`nav-link ${activePage === page ? 'active' : ''}`} onClick={() => navTo(page)}>
            {page === 'Dashboard' ? '▦' : page === 'Discover' ? '⌕' : page === 'My Organizations' ? '♧' : '☷'}
            <span>{page}</span>
          </button>
        ))}
        {officerOrganizations.length > 0 && (
          <button className={`nav-link ${activePage === 'Officer Requests' ? 'active' : ''}`} onClick={openOfficerScreen}>
            ✓<span>Officer Requests</span>
          </button>
        )}
        <div className="sidebar-bottom">
          <div className="profile-avatar">{user.name?.charAt(0).toUpperCase() || 'S'}</div>
          <div className="sidebar-user">
            <strong>{user.name}</strong><p>{user.email}</p>
            <button className="logout-button" onClick={handleLogout}>Log out</button>
          </div>
        </div>
      </aside>

      <main className="main-content">
        <header className="topbar">
          <div>
            <p className="breadcrumb">Home / {activePage}</p>
            <h1>{activePage}</h1>
          </div>
          <div className="topbar-right"><span className="status-dot" />Student Portal<div className="profile-avatar small">{user.name?.charAt(0).toUpperCase() || 'S'}</div></div>
        </header>

        {error && <div className="app-message error-message" role="alert">{error}</div>}
        {notice && <div className="app-message success-message" role="status">{notice}</div>}
        {loading && <p className="loading-message" role="status">Loading…</p>}

        {activePage === 'Dashboard' && (
          <>
            <section className="welcome">
              <div><span className="eyebrow">YOUR CAMPUS, YOUR COMMUNITY</span><h2>Find your people.</h2><p>Welcome, {user.name}! Discover organizations, explore your interests, and find a community where you belong.</p><button className="primary-button" onClick={() => navTo('Discover')}>Explore organizations →</button></div>
              <div className="welcome-art" aria-hidden="true"><div className="art-circle"><span>◎</span><span>♧</span><span>✳</span><span>✦</span></div></div>
            </section>
            <section className="stats-grid">
              <Stat icon="♧" label="Student organizations" value={organizations.length} tone="purple" />
              <Stat icon="☷" label="My requests" value={requests.length} tone="green" />
              <Stat icon="♡" label="My memberships" value={myOrganizations.length} tone="orange" />
            </section>
          </>
        )}

        {activePage === 'Discover' && (
          <section className="directory-section">
            <div className="section-heading"><div><span className="eyebrow">GET INVOLVED</span><h2>Discover organizations</h2><p>Find an organization that matches your passion.</p></div><span className="result-count">{filteredOrganizations.length} organizations</span></div>
            <div className="filters">
              <input type="search" placeholder="Search by name or cause..." value={search} onChange={(event) => setSearch(event.target.value)} aria-label="Search by name or cause" />
              <select value={college} onChange={(event) => setCollege(event.target.value)} aria-label="Filter by college"><option>All Colleges</option>{colleges.map((item) => <option key={item.code} value={item.code}>{item.name} ({item.code})</option>)}</select>
              <select value={type} onChange={(event) => setType(event.target.value)} aria-label="Filter by type"><option>All Types</option>{typeOptions.map((item) => <option key={item}>{item}</option>)}</select>
              <label className="open-filter"><input type="checkbox" checked={openOnly} onChange={(event) => setOpenOnly(event.target.checked)} />Open membership</label>
            </div>
            {filteredOrganizations.length > 0 ? (
              <div className="organization-grid">
                {filteredOrganizations.map((organization) => (
                  <article className="org-card" key={organization.id}>
                    <div className="org-card-top"><div className="org-avatar" style={{ backgroundColor: organization.color }}>{organization.name.split(/\s+/).map((part) => part[0]).join('').slice(0, 2)}</div><span className={`membership ${organization.open ? 'open' : 'closed'}`}>{organization.open ? '● Open' : '● Closed'}</span></div>
                    <span className="category">{organization.category}</span><h3>{organization.name}</h3><p className="org-cause">{organization.cause}</p>
                    <div className="org-meta"><span>{organization.college}</span><span>{organization.members} members</span></div>
                    {organization.request_status && <p className={`status-label status-${organization.request_status}`}>Request {organization.request_status}</p>}
                    <button className="outline-button" onClick={() => openOrganization(organization.id)}>View organization →</button>
                  </article>
                ))}
              </div>
            ) : <EmptyState title="No organizations found" description="Try another search term or change your filters." />}
          </section>
        )}

        {activePage === 'Organization' && selectedOrganization && (
          <OrganizationDetails
            organization={selectedOrganization}
            members={members}
            loading={loading}
            onBack={() => navTo('Discover')}
            onJoin={() => requestToJoin(selectedOrganization.id)}
            onWithdraw={() => withdrawRequest(selectedOrganization.id)}
            onViewMembers={viewMembers}
          />
        )}

        {activePage === 'My Requests' && (
          <section className="content-panel">
            <div className="section-heading"><div><span className="eyebrow">MEMBERSHIP</span><h2>My join requests</h2><p>Track decisions or withdraw a pending request.</p></div></div>
            {requests.length ? <div className="request-list">{requests.map((request) => (
              <article className="request-row" key={request.id}>
                <div><h3>{request.organization.name}</h3><p>{request.organization.category} · {request.organization.college}</p></div>
                <span className={`status-label status-${request.status}`}>{request.status}</span>
                {request.status === 'pending' && <button className="text-button danger-text" disabled={loading} onClick={() => withdrawRequest(request.organization_id)}>Withdraw request</button>}
              </article>
            ))}</div> : <EmptyState title="No join requests yet" description="Explore organizations and request to join a community." action="Discover organizations" onAction={() => navTo('Discover')} />}
          </section>
        )}

        {activePage === 'My Organizations' && (
          <section className="content-panel">
            <div className="section-heading"><div><span className="eyebrow">YOUR COMMUNITIES</span><h2>My organizations</h2><p>Organizations you belong to and your roles.</p></div></div>
            {myOrganizations.length ? <div className="organization-grid">{myOrganizations.map((membership) => (
              <article className="org-card" key={membership.id}><span className="category">{membership.category}</span><h3>{membership.name}</h3><p className="org-cause">{membership.college}</p><span className="role-pill">{membership.role}</span><button className="outline-button" onClick={() => openOrganization(membership.id)}>Open organization →</button></article>
            ))}</div> : <EmptyState title="You haven't joined an organization yet" description="Send a request to join an open organization to get started." action="Discover organizations" onAction={() => navTo('Discover')} />}
          </section>
        )}

        {activePage === 'Officer Requests' && (
          <section className="content-panel">
            <div className="section-heading"><div><span className="eyebrow">OFFICER TOOLS</span><h2>Join requests</h2><p>Review pending requests for organizations you lead.</p></div><select aria-label="Filter officer requests by organization" value={officerOrganizationId} onChange={async (event) => { setOfficerOrganizationId(event.target.value); await runAction(() => loadOfficerRequests(event.target.value)) }}><option value="all">All my organizations</option>{officerOrganizations.map((organization) => <option key={organization.id} value={organization.id}>{organization.name}</option>)}</select></div>
            {officerRequests.length ? <div className="request-list">{officerRequests.map((request) => (
              <article className="request-row" key={request.id}>
                <div><h3>{request.user.name}</h3><p>{request.user.email} · {request.organization.name}</p></div>
                <span className={`status-label status-${request.status}`}>{request.status}</span>
                {request.status === 'pending' && <div className="decision-buttons"><button className="approve-button" disabled={loading} onClick={() => decideRequest(request.id, 'approved')}>Approve</button><button className="decline-button" disabled={loading} onClick={() => decideRequest(request.id, 'declined')}>Decline</button></div>}
              </article>
            ))}</div> : <EmptyState title="No requests to review" description="Pending join requests for your organizations will appear here." />}
          </section>
        )}

        <footer>OrgLife · Student Organization Management System</footer>
      </main>
    </div>
  )
}

function Stat({ icon, label, value, tone }) {
  return <div className="stat-card"><div className={`stat-icon ${tone}`}>{icon}</div><p>{label}</p><h3>{String(value).padStart(2, '0')}</h3></div>
}

function EmptyState({ title, description, action, onAction }) {
  return <div className="empty-state"><h3>{title}</h3><p>{description}</p>{action && <button className="outline-button" onClick={onAction}>{action}</button>}</div>
}

function OrganizationDetails({ organization, members, loading, onBack, onJoin, onWithdraw, onViewMembers }) {
  const isMember = Boolean(organization.membership_role)
  const canRequest = organization.open && !isMember && organization.request_status !== 'pending'

  return (
    <section className="content-panel organization-detail">
      <button className="text-button" onClick={onBack}>← Back to organizations</button>
      <div className="detail-heading">
        <div className="org-avatar large-avatar" style={{ backgroundColor: organization.color }}>{organization.name.split(/\s+/).map((part) => part[0]).join('').slice(0, 2)}</div>
        <div><span className="category">{organization.category} · {organization.college}</span><h2>{organization.name}</h2><p>{organization.cause}</p></div>
        <span className={`membership ${organization.open ? 'open' : 'closed'}`}>{organization.open ? '● Open membership' : '● Membership closed'}</span>
      </div>
      <div className="detail-columns">
        <section className="detail-block"><h3>Our mission</h3><p>{organization.mission}</p></section>
        <section className="detail-block"><h3>Officers</h3>{organization.officers.length ? <ul className="plain-list">{organization.officers.map((officer) => <li key={`${officer.name}-${officer.role}`}><strong>{officer.name}</strong><span>{officer.role}</span></li>)}</ul> : <p>Officer information hasn't been added yet.</p>}</section>
      </div>
      <section className="detail-block"><h3>Events</h3>{organization.events.length ? <div className="event-list">{organization.events.map((event) => <article className="event-card" key={event.id}><strong>{event.title}</strong><time>{new Date(event.starts_at).toLocaleString()}</time>{event.location && <span>{event.location}</span>}{event.description && <p>{event.description}</p>}</article>)}</div> : <p>No events are scheduled yet.</p>}</section>
      <div className="detail-actions">
        {canRequest && <button className="primary-button" disabled={loading} onClick={onJoin}>{organization.request_status === 'declined' || organization.request_status === 'withdrawn' ? 'Request to join again' : 'Request to join'}</button>}
        {organization.request_status === 'pending' && <><span className="status-label status-pending">Request pending</span><button className="text-button danger-text" disabled={loading} onClick={onWithdraw}>Withdraw request</button></>}
        {organization.request_status === 'declined' && <span className="status-label status-declined">Request declined</span>}
        {isMember && <span className="role-pill">Member · {organization.membership_role}</span>}
        {isMember && <button className="outline-button members-toggle" disabled={loading} onClick={onViewMembers}>{members.length ? 'Refresh member list' : 'View member list'}</button>}
      </div>
      {members.length > 0 && <section className="detail-block"><h3>Members and roles ({members.length})</h3><div className="member-list">{members.map((member) => <div className="member-row" key={member.email}><div><strong>{member.name}</strong><span>{member.email}</span></div><span className="role-pill">{member.role}</span></div>)}</div></section>}
    </section>
  )
}

export default App

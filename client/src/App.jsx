import { useEffect, useMemo, useState } from 'react'
import './App.css'
import Auth from './auth'
import api from './api'
import Icon from './Icon'

function collegeTheme(college) {
  const themes = {
    CCS: 'org-theme-ccs',
    COE: 'org-theme-coe',
    CBA: 'org-theme-cba',
    CED: 'org-theme-ced',
    CAS: 'org-theme-cas',
    COC: 'org-theme-coc',
  }

  return themes[college] || 'org-theme-default'
}

const collegeLogoFiles = {
  CCS: 'CCS logo.jpg',
  COE: 'COE logo.jpg',
  CBA: 'CBA logo.jpeg',
  CED: 'CED logo.jpg',
  CAS: 'CAS logo.jpg',
  COC: 'COC logo.jpg',
}

function CollegeLogo({ college, organizationName, className = '' }) {
  const [hasLogo, setHasLogo] = useState(true)

  if (!college && !organizationName) return null

  const isStudentGovernment = organizationName?.trim().toLowerCase() === 'student government'
  const logoFile = isStudentGovernment ? 'SPC logo.jpg' : collegeLogoFiles[college]
  const fallbackLabel = college || organizationName?.split(/\s+/).map((part) => part[0]).join('').slice(0, 2)

  return (
    <span className={`college-logo ${className}`} aria-label={`${organizationName || college} logo`}>
      {hasLogo && logoFile
        ? <img src={`${import.meta.env.BASE_URL}college-logos/${encodeURIComponent(logoFile)}`} alt={`${organizationName || college} logo`} onError={() => setHasLogo(false)} />
        : <span aria-hidden="true">{fallbackLabel}</span>}
    </span>
  )
}

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
  const [officerMembers, setOfficerMembers] = useState([])
  const [officerEvents, setOfficerEvents] = useState([])
  const [eventAttendance, setEventAttendance] = useState([])
  const [selectedEventId, setSelectedEventId] = useState('')
  const [announcements, setAnnouncements] = useState([])
  const [myEvents, setMyEvents] = useState([])
  const [announcementTitle, setAnnouncementTitle] = useState('')
  const [announcementBody, setAnnouncementBody] = useState('')
  const [profileName, setProfileName] = useState(() => user?.name || '')
  const [profilePictureFile, setProfilePictureFile] = useState(null)
  const [profilePicturePreview, setProfilePicturePreview] = useState('')
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

  useEffect(() => () => {
    if (profilePicturePreview) URL.revokeObjectURL(profilePicturePreview)
  }, [profilePicturePreview])

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

  async function loadAnnouncements() {
    const response = await api.get('/announcements')
    setAnnouncements(response.data.data)
  }

  async function loadMyEvents() {
    const response = await api.get('/my-events')
    setMyEvents(response.data.data)
  }

  async function loadOfficerRequests(organizationId = officerOrganizationId) {
    const params = organizationId === 'all' ? {} : { organization_id: organizationId }
    const response = await api.get('/officer/requests', { params })
    setOfficerRequests(response.data.data)
  }

  async function loadOfficerMembers(organizationId = officerOrganizationId) {
    const targetOrganizations = organizationId === 'all'
      ? officerOrganizations
      : officerOrganizations.filter((organization) => String(organization.id) === String(organizationId))
    const memberships = await Promise.all(targetOrganizations.map(async (organization) => {
      const response = await api.get(`/organizations/${organization.id}/members`)
      return response.data.data.map((member) => ({
        ...member,
        organization: { id: organization.id, name: organization.name, college: organization.college },
      }))
    }))
    setOfficerMembers(memberships.flat())
  }

  async function loadOfficerEvents(organizationId = officerOrganizationId) {
    const targetOrganizations = organizationId === 'all'
      ? officerOrganizations
      : officerOrganizations.filter((organization) => String(organization.id) === String(organizationId))
    const events = await Promise.all(targetOrganizations.map(async (organization) => {
      const response = await api.get(`/officer/organizations/${organization.id}/events`)
      return response.data.data.map((event) => ({
        ...event,
        organization: { id: organization.id, name: organization.name, college: organization.college },
      }))
    }))
    setOfficerEvents(events.flat())
    setEventAttendance([])
    setSelectedEventId('')
  }

  useEffect(() => {
    if (!user) return

    let cancelled = false
    Promise.all([
      api.get('/organizations'),
      api.get('/membership-requests'),
      api.get('/my-organizations'),
      api.get('/announcements'),
      api.get('/my-events'),
      api.get('/user'),
    ])
      .then(([orgResponse, requestResponse, membershipResponse, announcementResponse, eventResponse, userResponse]) => {
        if (cancelled) return
        if (user.is_admin !== userResponse.data.user.is_admin) {
          localStorage.setItem('orglife_user', JSON.stringify(userResponse.data.user))
          setUser(userResponse.data.user)
        }
        setOrganizations(orgResponse.data.data)
        setColleges(orgResponse.data.meta.colleges)
        setRequests(requestResponse.data.data)
        setMyOrganizations(membershipResponse.data.data)
        setAnnouncements(announcementResponse.data.data)
        setMyEvents(eventResponse.data.data)
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
    setProfileName(userData.name)
  }

  function updateUserProfile(userData) {
    localStorage.setItem('orglife_user', JSON.stringify(userData))
    setUser(userData)
    setProfileName(userData.name)
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
      setOfficerMembers([])
      setOfficerEvents([])
      setEventAttendance([])
      setAnnouncements([])
      setMyEvents([])
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

  async function saveProfile(event) {
    event.preventDefault()
    await runAction(async () => {
      const formData = new FormData()
      formData.append('name', profileName.trim())
      formData.append('_method', 'PATCH')
      if (profilePictureFile) formData.append('profile_picture', profilePictureFile)
      const response = await api.post('/profile', formData, {
        headers: { 'Content-Type': 'multipart/form-data' },
      })
      updateUserProfile(response.data.user)
      setProfilePictureFile(null)
      setProfilePicturePreview('')
    }, 'Your profile has been updated.')
  }

  async function removeProfilePicture() {
    await runAction(async () => {
      const response = await api.delete('/profile-picture')
      updateUserProfile(response.data.user)
      setProfilePictureFile(null)
      setProfilePicturePreview('')
    }, 'Your profile picture has been removed.')
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
      setOfficerOrganizationId('all')
      await Promise.all([
        loadOfficerRequests('all'),
        loadOfficerMembers('all'),
        loadOfficerEvents('all'),
      ])
      setActivePage('Officer Requests')
    })
  }

  async function changeOfficerOrganization(organizationId) {
    setOfficerOrganizationId(organizationId)
    await runAction(() => Promise.all([
      loadOfficerRequests(organizationId),
      loadOfficerMembers(organizationId),
      loadOfficerEvents(organizationId),
    ]))
  }

  async function rsvpToEvent(eventId, rsvped) {
    if (!selectedOrganization) return
    await runAction(async () => {
      const path = `/organizations/${selectedOrganization.id}/events/${eventId}/rsvp`
      if (rsvped) {
        await api.delete(path)
      } else {
        await api.post(path)
      }
      const [organizationResponse] = await Promise.all([
        api.get(`/organizations/${selectedOrganization.id}`),
        loadMyEvents(),
      ])
      setSelectedOrganization(organizationResponse.data.data)
    }, rsvped ? 'Your RSVP has been cancelled.' : 'You are RSVP’d for this event.')
  }

  async function openEventAttendance(eventId) {
    await runAction(async () => {
      const response = await api.get(`/officer/events/${eventId}/attendance`)
      setEventAttendance(response.data.data)
      setSelectedEventId(String(eventId))
    })
  }

  async function markAttendance(eventId, userId) {
    await runAction(async () => {
      await api.post(`/officer/events/${eventId}/attendance/${userId}`)
      const event = officerEvents.find((item) => String(item.id) === String(eventId))
      if (!event) return
      const [attendanceResponse, eventsResponse] = await Promise.all([
        api.get(`/officer/events/${eventId}/attendance`),
        api.get(`/officer/organizations/${event.organization_id}/events`),
      ])
      const refreshedEvents = eventsResponse.data.data.map((item) => ({
        ...item,
        organization: event.organization,
      }))
      setOfficerEvents((currentEvents) => [
        ...currentEvents.filter((item) => item.organization_id !== event.organization_id),
        ...refreshedEvents,
      ])
      setEventAttendance(attendanceResponse.data.data)
      setSelectedEventId(String(eventId))
    }, 'Attendance marked.')
  }

  async function decideRequest(requestId, status) {
    await runAction(async () => {
      await api.patch(`/officer/requests/${requestId}`, { status })
      await Promise.all([loadOfficerRequests(), loadRequests(), loadMyOrganizations()])
      await loadOfficerMembers()
    }, `Request ${status}.`)
  }

  async function removeOrganizationMember(organizationId, memberId) {
    await runAction(async () => {
      await api.delete(`/officer/organizations/${organizationId}/members/${memberId}`)
      await loadOfficerMembers()
    }, 'Member removed from the organization.')
  }

  async function promoteOrganizationMember(organizationId, memberId) {
    await runAction(async () => {
      await api.patch(`/officer/organizations/${organizationId}/members/${memberId}`)
      await Promise.all([loadOfficerMembers(), loadMyOrganizations()])
    }, 'Member promoted to officer.')
  }

  async function postAnnouncement(title, body) {
    if (!selectedOrganization) return
    await runAction(async () => {
      await api.post(`/organizations/${selectedOrganization.id}/announcements`, { title, body })
      const [organizationResponse] = await Promise.all([
        api.get(`/organizations/${selectedOrganization.id}`),
        loadAnnouncements(),
      ])
      setSelectedOrganization(organizationResponse.data.data)
      setAnnouncementTitle('')
      setAnnouncementBody('')
    }, 'Announcement posted.')
  }

  async function deleteAnnouncement(organizationId, announcementId) {
    if (!window.confirm('Delete this announcement? This cannot be undone.')) return
    await runAction(async () => {
      await api.delete(`/organizations/${organizationId}/announcements/${announcementId}`)
      await loadAnnouncements()
      if (String(selectedOrganization?.id) === String(organizationId)) {
        const response = await api.get(`/organizations/${organizationId}`)
        setSelectedOrganization(response.data.data)
      }
    }, 'Announcement deleted.')
  }

  async function deleteEvent(organizationId, eventId) {
    if (!window.confirm('Delete this event? Its RSVP and attendance records will also be removed.')) return
    await runAction(async () => {
      await api.delete(`/organizations/${organizationId}/events/${eventId}`)
      await Promise.all([loadMyEvents(), loadOfficerEvents()])
      setEventAttendance([])
      setSelectedEventId('')
      if (String(selectedOrganization?.id) === String(organizationId)) {
        const response = await api.get(`/organizations/${organizationId}`)
        setSelectedOrganization(response.data.data)
      }
    }, 'Event deleted.')
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
  const officerOrganizations = user?.is_admin
    ? organizations
    : myOrganizations.filter((organization) => organization.role === 'President')

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
          <div className="brand-icon" aria-hidden="true"><span /></div>
          <div><h2>OrgLife</h2><p>Campus community</p></div>
        </div>
        <p className="nav-label">MENU</p>
        {['Dashboard', 'Discover', 'My Organizations', ...(!user.is_admin ? ['My Requests'] : [])].map((page) => (
          <button key={page} className={`nav-link ${activePage === page ? 'active' : ''}`} onClick={() => navTo(page)}>
            <Icon name={page === 'Dashboard' ? 'dashboard' : page === 'Discover' ? 'search' : page === 'My Organizations' ? 'organizations' : 'requests'} />
            <span>{page}</span>
          </button>
        ))}
        <button className={`nav-link ${activePage === 'Profile Settings' ? 'active' : ''}`} onClick={() => navTo('Profile Settings')}>
          <Icon name="members" /><span>Profile Settings</span>
        </button>
        {officerOrganizations.length > 0 && (
          <button className={`nav-link ${activePage === 'Officer Requests' ? 'active' : ''}`} onClick={openOfficerScreen}>
            <Icon name="officer" /><span>Officer Tools</span>
          </button>
        )}
        <div className="sidebar-bottom">
          <button
            type="button"
            className="profile-navigation-button"
            onClick={() => navTo('Profile Settings')}
            aria-label="Open profile settings"
          >
            <UserAvatar name={user.name} src={user.profile_picture_url} />
          </button>
          <div className="sidebar-user">
            <button type="button" className="profile-name-button" onClick={() => navTo('Profile Settings')}>
              {user.name}
            </button>
            <p>{user.email}</p>
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
          <div className="topbar-right"><span className="status-dot" />Student Portal<button type="button" className="profile-navigation-button" onClick={() => navTo('Profile Settings')} aria-label="Open profile settings"><UserAvatar name={user.name} src={user.profile_picture_url} size="small" /></button></div>
        </header>

        {error && <div className="app-message error-message" role="alert">{error}</div>}
        {notice && <div className="app-message success-message" role="status">{notice}</div>}
        {loading && <p className="loading-message" role="status">Loading…</p>}

        {activePage === 'Dashboard' && (
          <>
            <section className="welcome">
              <div><span className="eyebrow">YOUR CAMPUS, YOUR COMMUNITY</span><div className="welcome-profile"><button type="button" className="profile-navigation-button" onClick={() => navTo('Profile Settings')} aria-label="Open profile settings"><UserAvatar name={user.name} src={user.profile_picture_url} size="compact" /></button><button type="button" className="profile-name-button welcome-name-button" onClick={() => navTo('Profile Settings')}>Welcome, {user.name}!</button></div><h2>Find your people.</h2><p>Discover organizations, explore your interests, and find a community where you belong.</p><button className="primary-button" onClick={() => navTo('Discover')}>Explore organizations <Icon name="arrowRight" /></button></div>
              <div className="welcome-art" aria-hidden="true"><div className="art-circle"><span /><span /><span /></div></div>
            </section>
            <section className="stats-grid">
              <Stat icon="organizations" label="Student organizations" value={organizations.length} tone="purple" />
              <Stat icon="requests" label="My requests" value={requests.length} tone="green" />
              <Stat icon="members" label="My memberships" value={myOrganizations.length} tone="orange" />
            </section>
            <section className="content-panel dashboard-section">
              <div className="section-heading"><div><span className="eyebrow">YOUR COMMUNITIES</span><h2>Organizations you belong to</h2><p>Quick access to your organizations and member roles.</p></div><span className="result-count">{myOrganizations.length} joined</span></div>
              {myOrganizations.length ? <div className="dashboard-org-list">{myOrganizations.map((membership) => (
                <article className={`dashboard-org-row ${collegeTheme(membership.college)}`} key={membership.id}>
                  <div className="dashboard-org-info"><CollegeLogo college={membership.college} organizationName={membership.name} /><div><h3>{membership.name}</h3><p>{membership.category} · {membership.college}</p></div></div>
                  <span className="role-pill">{membership.role}</span>
                  <button className="text-button" onClick={() => openOrganization(membership.id)}>Open <Icon name="arrowRight" /></button>
                </article>
              ))}</div> : <EmptyState title="No organizations yet" description="Join an organization to see it here." action="Discover organizations" onAction={() => navTo('Discover')} />}
            </section>
            <section className="content-panel dashboard-section">
              <div className="section-heading"><div><span className="eyebrow">COMMUNITY UPDATES</span><h2>Announcements</h2><p>Latest posts from every organization you belong to.</p></div></div>
              <AnnouncementList
                announcements={announcements}
                onOpenOrganization={openOrganization}
                onDelete={deleteAnnouncement}
                loading={loading}
                canDelete={(announcement) => myOrganizations.some((membership) =>
                  String(membership.id) === String(announcement.organization.id) && membership.role === 'President') || user.is_admin}
              />
            </section>
            <section className="content-panel dashboard-section">
              <div className="section-heading"><div><span className="eyebrow">YOUR CALENDAR</span><h2>Organization events</h2><p>Upcoming and past events from every organization you’ve joined.</p></div></div>
              <EventList
                events={myEvents}
                onOpenOrganization={openOrganization}
                onDelete={deleteEvent}
                loading={loading}
                canDelete={(event) => myOrganizations.some((membership) =>
                  String(membership.id) === String(event.organization_id) && membership.role === 'President') || user.is_admin}
              />
            </section>
          </>
        )}

        {activePage === 'Profile Settings' && (
          <section className="content-panel profile-settings">
            <div className="section-heading">
              <div><span className="eyebrow">YOUR ACCOUNT</span><h2>Profile settings</h2><p>Update how you appear to your campus community.</p></div>
            </div>
            <form className="profile-settings-form" onSubmit={saveProfile}>
              <div className="profile-picture-editor">
                <UserAvatar
                  name={profileName || user.name}
                  src={profilePicturePreview || user.profile_picture_url}
                  size="large"
                />
                <div>
                  <strong>Profile picture</strong>
                  <p>Use a JPG, PNG, or WebP image up to 5 MB. Images are cropped to fit your avatar.</p>
                  <label className="outline-button profile-upload-button">
                    Choose image
                    <input
                      type="file"
                      accept="image/jpeg,image/png,image/webp"
                      onChange={(changeEvent) => {
                        const file = changeEvent.target.files?.[0]
                        if (!file) return
                        setProfilePictureFile(file)
                        setProfilePicturePreview(URL.createObjectURL(file))
                      }}
                    />
                  </label>
                  {(profilePicturePreview || user.profile_picture_url) && (
                    <button type="button" className="text-button danger-text" disabled={loading} onClick={removeProfilePicture}>Remove picture</button>
                  )}
                </div>
              </div>
              <label className="profile-name-field">
                Display name
                <input value={profileName} onChange={(changeEvent) => setProfileName(changeEvent.target.value)} required maxLength={255} />
              </label>
              <label className="profile-name-field">
                Email address
                <input value={user.email} readOnly />
              </label>
              <button className="primary-button" disabled={loading || !profileName.trim()}>Save profile</button>
            </form>
          </section>
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
                  <article className={`org-card ${collegeTheme(organization.college)}`} key={organization.id}>
                    <div className="org-card-top"><CollegeLogo college={organization.college} organizationName={organization.name} className="org-avatar" /><span className={`membership ${organization.open ? 'open' : 'closed'}`}>{organization.open ? 'Open' : 'Closed'}</span></div>
                    <span className="category">{organization.category}</span><h3>{organization.name}</h3><p className="org-cause">{organization.cause}</p>
                    <div className="org-meta"><span>{organization.college}</span><span>{organization.members} members</span></div>
                    {organization.request_status && organization.request_status !== 'approved' && <p className={`status-label status-${organization.request_status}`}>Request {organization.request_status}</p>}
                    <button className="outline-button" onClick={() => openOrganization(organization.id)}>View organization <Icon name="arrowRight" /></button>
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
            onPostAnnouncement={postAnnouncement}
            announcementTitle={announcementTitle}
            announcementBody={announcementBody}
            onAnnouncementTitleChange={setAnnouncementTitle}
            onAnnouncementBodyChange={setAnnouncementBody}
            onRsvp={rsvpToEvent}
            onDeleteAnnouncement={deleteAnnouncement}
            onDeleteEvent={deleteEvent}
            isAdmin={user.is_admin}
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
              <article className={`org-card ${collegeTheme(membership.college)}`} key={membership.id}><div className="org-card-top"><CollegeLogo college={membership.college} organizationName={membership.name} className="org-avatar" /><span className="role-pill">{membership.role}</span></div><span className="category">{membership.category}</span><h3>{membership.name}</h3><p className="org-cause">{membership.college}</p><button className="outline-button" onClick={() => openOrganization(membership.id)}>Open organization <Icon name="arrowRight" /></button></article>
            ))}</div> : <EmptyState title="You haven't joined an organization yet" description="Send a request to join an open organization to get started." action="Discover organizations" onAction={() => navTo('Discover')} />}
          </section>
        )}

        {activePage === 'Officer Requests' && (
          <section className="content-panel">
            <div className="section-heading"><div><span className="eyebrow">OFFICER TOOLS</span><h2>Join requests and members</h2><p>Review requests and manage membership for organizations you lead.</p></div><select aria-label="Filter officer tools by organization" value={officerOrganizationId} onChange={(event) => changeOfficerOrganization(event.target.value)}><option value="all">All my organizations</option>{officerOrganizations.map((organization) => <option key={organization.id} value={organization.id}>{organization.name}</option>)}</select></div>
            {officerRequests.length ? <div className="request-list">{officerRequests.map((request) => (
              <article className="request-row" key={request.id}>
                <div className="request-person">
                  <UserAvatar name={request.user.name} src={request.user.profile_picture_url} size="compact" />
                  <div><h3>{request.user.name}</h3><p>{request.user.email} · {request.organization.name}</p></div>
                </div>
                <span className={`status-label status-${request.status}`}>{request.status}</span>
                {request.status === 'pending' && <div className="decision-buttons"><button className="approve-button" disabled={loading} onClick={() => decideRequest(request.id, 'approved')}>Approve</button><button className="decline-button" disabled={loading} onClick={() => decideRequest(request.id, 'declined')}>Decline</button></div>}
              </article>
            ))}</div> : <EmptyState title="No requests to review" description="Pending join requests for your organizations will appear here." />}
            <section className="officer-members">
              <div className="officer-section-heading">
                <div><h3>Manage members</h3><p>Members are grouped by organization and role.</p></div>
                <span className="member-total">{officerMembers.length} members</span>
              </div>
              {officerMembers.length ? <div className="officer-member-groups">{officerOrganizations
                .filter((organization) => officerMembers.some((member) => member.organization.id === organization.id))
                .map((organization) => {
                  const organizationMembers = officerMembers.filter(
                    (member) => member.organization.id === organization.id,
                  )
                  const leadership = organizationMembers.filter((member) => member.role !== 'Member')
                  const regularMembers = organizationMembers.filter((member) => member.role === 'Member')
                  const isPresident = officerOrganizations.some(
                    (membership) => membership.id === organization.id,
                  )

                  return (
                    <article className="officer-organization-group" key={organization.id}>
                      <header className="officer-organization-heading">
                        <div><h4>{organization.name}</h4><span>{organizationMembers.length} members</span></div>
                      </header>
                      {leadership.length > 0 && <section className="officer-role-group">
                        <h5>Leadership team <span>{leadership.length}</span></h5>
                        <div className="officer-roster">{leadership.map((member) => (
                          <div className="officer-member-row" key={member.id}>
                            <div className="officer-member-identity">
                              <UserAvatar name={member.name} src={member.profile_picture_url} size="member" />
                              <div><strong>{member.name}</strong><span>{member.email}</span></div>
                            </div>
                            <span className="role-pill">{member.role}</span>
                            <div className="decision-buttons">
                              {isPresident && member.role !== 'President' && member.user_id !== user.id
                                ? <button className="decline-button" disabled={loading} onClick={() => removeOrganizationMember(organization.id, member.id)}>Remove</button>
                                : <span className="member-row-spacer" />}
                            </div>
                          </div>
                        ))}</div>
                      </section>}
                      {regularMembers.length > 0 && <section className="officer-role-group">
                        <h5>Members <span>{regularMembers.length}</span></h5>
                        <div className="officer-roster">{regularMembers.map((member) => (
                          <div className="officer-member-row" key={member.id}>
                            <div className="officer-member-identity">
                              <UserAvatar name={member.name} src={member.profile_picture_url} size="member" />
                              <div><strong>{member.name}</strong><span>{member.email}</span></div>
                            </div>
                            <span className="role-pill">{member.role}</span>
                            <div className="decision-buttons">
                              {isPresident && <button className="approve-button" disabled={loading} onClick={() => promoteOrganizationMember(organization.id, member.id)}>Make officer</button>}
                              {isPresident && member.user_id !== user.id && <button className="decline-button" disabled={loading} onClick={() => removeOrganizationMember(organization.id, member.id)}>Remove</button>}
                            </div>
                          </div>
                        ))}</div>
                      </section>}
                    </article>
                  )
                })}</div> : <EmptyState title="No members found" description="Members of your organizations will appear here." />}
            </section>
            <section className="officer-members">
              <h3>Event attendance</h3>
              {officerEvents.length ? <div className="request-list">{officerEvents.map((event) => (
                  <article className="request-row event-management-row" key={event.id}>
                    <div><h3>{event.title}</h3><p>{event.organization.name} · {new Date(event.starts_at).toLocaleString()} · {event.attendance_count} attended · {event.rsvp_count} RSVPs</p></div>
                    <div className="decision-buttons">
                      <button className="outline-button attendance-action" onClick={() => openEventAttendance(event.id)}>{String(event.id) === selectedEventId ? 'Refresh roster' : 'Mark attendance'}</button>
                      <button className="decline-button" disabled={loading} onClick={() => deleteEvent(event.organization_id, event.id)}>Delete event</button>
                    </div>
                  </article>
                ))}</div> : <EmptyState title="No events to manage" description="Your organizations have no scheduled events." />}
              {selectedEventId && <div className="member-list attendance-roster">{eventAttendance.map((attendee) => (
                <div className="member-row" key={attendee.user_id}>
                  <div className="request-person attendance-person">
                    <UserAvatar name={attendee.name} src={attendee.profile_picture_url} size="compact" />
                    <div className="attendance-person-details">
                      <strong>{attendee.name}</strong>
                      <span className="attendance-email">{attendee.email}</span>
                      <span className="attendance-role">{attendee.role}{attendee.rsvped ? ' · RSVP’d' : ''}</span>
                    </div>
                  </div>
                  {attendee.attended
                    ? <span className="status-label status-approved">Attended</span>
                    : <div className="decision-buttons"><button className="approve-button" disabled={loading} onClick={() => markAttendance(selectedEventId, attendee.user_id)}>Mark attended</button></div>}
                </div>
              ))}</div>}
            </section>
          </section>
        )}

        <footer>OrgLife · Student Organization Management System</footer>
      </main>
    </div>
  )
}

function Stat({ icon, label, value, tone }) {
  return <div className="stat-card"><div className={`stat-icon ${tone}`}><Icon name={icon} /></div><p>{label}</p><h3>{String(value).padStart(2, '0')}</h3></div>
}

function EmptyState({ title, description, action, onAction }) {
  return <div className="empty-state"><h3>{title}</h3><p>{description}</p>{action && <button className="outline-button" onClick={onAction}>{action}</button>}</div>
}

function UserAvatar({ name, src, size = '' }) {
  const [imageFailed, setImageFailed] = useState(false)
  const initials = name?.trim().charAt(0).toUpperCase() || 'U'

  return (
    <span className={`profile-avatar ${size}`.trim()}>
      {src && !imageFailed
        ? <img src={src} alt={`${name || 'User'} profile`} onError={() => setImageFailed(true)} />
        : initials}
    </span>
  )
}

function OrganizationDetails({
  organization,
  members,
  loading,
  onBack,
  onJoin,
  onWithdraw,
  onViewMembers,
  onPostAnnouncement,
  announcementTitle,
  announcementBody,
  onAnnouncementTitleChange,
  onAnnouncementBodyChange,
  onRsvp,
  onDeleteAnnouncement,
  onDeleteEvent,
  isAdmin = false,
}) {
  const isMember = Boolean(organization.membership_role)
  const isOfficer = isAdmin || organization.membership_role === 'President'
  const canRequest = organization.open && !isMember && organization.request_status !== 'pending'

  return (
    <section className={`content-panel organization-detail ${collegeTheme(organization.college)}`}>
      <button className="text-button back-button" onClick={onBack}><Icon name="arrowLeft" />Back to organizations</button>
      <div className="detail-heading">
        <CollegeLogo college={organization.college} organizationName={organization.name} className="org-avatar large-avatar" />
        <div><span className="category">{organization.category} · {organization.college}</span><h2>{organization.name}</h2><p>{organization.cause}</p></div>
        <span className={`membership ${organization.open ? 'open' : 'closed'}`}>{organization.open ? 'Open membership' : 'Membership closed'}</span>
      </div>
      <div className="detail-columns">
        <section className="detail-block"><h3>Our mission</h3><p>{organization.mission}</p></section>
        <section className="detail-block"><h3>Officers</h3>{organization.officers.length ? <ul className="plain-list">{organization.officers.map((officer) => <li key={`${officer.name}-${officer.role}`}><div className="officer-list-person"><UserAvatar name={officer.name} src={officer.profile_picture_url} size="compact" /><strong>{officer.name}</strong></div><span>{officer.role}</span></li>)}</ul> : <p>Officer information hasn't been added yet.</p>}</section>
      </div>
      <section className="detail-block">
        <h3>Organization calendar</h3>
        <EventList
          events={organization.events}
          organizationCollege={organization.college}
          isMember={isMember}
          loading={loading}
          onRsvp={onRsvp}
          onDelete={onDeleteEvent}
          canDelete={() => isOfficer}
        />
      </section>
      {(isMember || isAdmin) && <section className="detail-block">
        <h3>Organization announcements</h3>
        {organization.announcements?.length ? <AnnouncementList
          announcements={organization.announcements}
          onDelete={onDeleteAnnouncement}
          loading={loading}
          canDelete={() => isOfficer}
        /> : <p>No announcements have been posted yet.</p>}
        {isOfficer && <form className="announcement-form" onSubmit={(event) => { event.preventDefault(); onPostAnnouncement(announcementTitle, announcementBody) }}>
          <label>Title<input required maxLength="160" value={announcementTitle} onChange={(event) => onAnnouncementTitleChange(event.target.value)} /></label>
          <label>Announcement<textarea required maxLength="5000" rows="4" value={announcementBody} onChange={(event) => onAnnouncementBodyChange(event.target.value)} /></label>
          <button className="primary-button" disabled={loading || !announcementTitle.trim() || !announcementBody.trim()}>Post announcement</button>
        </form>}
      </section>}
      <div className="detail-actions">
        {canRequest && <button className="primary-button" disabled={loading} onClick={onJoin}>{organization.request_status === 'declined' || organization.request_status === 'withdrawn' ? 'Request to join again' : 'Request to join'}</button>}
        {organization.request_status === 'pending' && <><span className="status-label status-pending">Request pending</span><button className="text-button danger-text" disabled={loading} onClick={onWithdraw}>Withdraw request</button></>}
        {organization.request_status === 'declined' && <span className="status-label status-declined">Request declined</span>}
        {isMember && <span className="role-pill">Member · {organization.membership_role}</span>}
        {(isMember || isAdmin) && <button className="outline-button members-toggle" disabled={loading} onClick={onViewMembers}>{members.length ? 'Refresh member list' : 'View member list'}</button>}
      </div>
      {members.length > 0 && <section className="detail-block"><h3>Members and roles ({members.length})</h3><div className="member-list">{members.map((member) => <div className="member-row" key={member.email}><div className="request-person member-identity"><UserAvatar name={member.name} src={member.profile_picture_url} size="compact" /><div className="member-identity-details"><strong>{member.name}</strong><span>{member.email}</span></div></div><span className="role-pill">{member.role}</span></div>)}</div></section>}
    </section>
  )
}

function EventList({ events, isMember = false, loading = false, onRsvp, onOpenOrganization, organizationCollege, onDelete, canDelete }) {
  if (!events.length) {
    return <p>No events are scheduled yet.</p>
  }

  const sections = [
    { title: 'Upcoming events', events: events.filter((event) => !event.is_past) },
    { title: 'Past events', events: events.filter((event) => event.is_past) },
  ].filter((section) => section.events.length)

  return <div className="event-calendar">
    {sections.map((section) => (
      <section className="calendar-section" key={section.title}>
        <h4>{section.title}</h4>
        <div className="event-list">{section.events.map((event) => (
          <article className={`event-card ${collegeTheme(event.organization?.college || organizationCollege)}`} key={event.id}>
            <strong>{event.title}</strong>
            <time>{new Date(event.starts_at).toLocaleString()}</time>
            {event.organization && <span className="event-organization"><CollegeLogo college={event.organization.college} organizationName={event.organization.name} className="college-logo-small" />{event.organization.name}</span>}
            {event.location && <span>{event.location}</span>}
            {event.description && <p>{event.description}</p>}
            <div className="event-counts">
              <span>{event.rsvp_count} going</span>
              <span>{event.attendance_count} attended</span>
            </div>
            {isMember && !event.is_past && onRsvp && (
              <button
                className={event.rsvped ? 'decline-button' : 'approve-button'}
                disabled={loading}
                onClick={() => onRsvp(event.id, event.rsvped)}
              >
                {event.rsvped ? 'Cancel RSVP' : 'RSVP'}
              </button>
            )}
            {onOpenOrganization && event.organization && (
              <button className="text-button" onClick={() => onOpenOrganization(event.organization.id)}>
                Open organization <Icon name="arrowRight" />
              </button>
            )}
            {onDelete && canDelete?.(event) && (
              <button className="decline-button" disabled={loading} onClick={() => onDelete(event.organization_id, event.id)}>
                Delete event
              </button>
            )}
          </article>
        ))}</div>
      </section>
    ))}
  </div>
}

function AnnouncementList({ announcements, onOpenOrganization, onDelete, canDelete, loading = false }) {
  if (!announcements.length) {
    return <EmptyState title="No announcements yet" description="Posts from your organizations will appear here." />
  }

  return <div className="announcement-list">{announcements.map((announcement) => (
    <article className={`announcement-card ${collegeTheme(announcement.organization.college)}`} key={announcement.id}>
      <div className="announcement-meta">
        <span className="announcement-organization"><CollegeLogo college={announcement.organization.college} organizationName={announcement.organization.name} className="college-logo-small" /><strong>{announcement.organization.name}</strong></span>
        <time>{new Date(announcement.created_at).toLocaleString()}</time>
      </div>
      <h3>{announcement.title}</h3>
      <p>{announcement.body}</p>
      <div className="announcement-author"><UserAvatar name={announcement.author.name} src={announcement.author.profile_picture_url} size="tiny" /><span>Posted by {announcement.author.name}</span></div>
      {onOpenOrganization && <button className="text-button" onClick={() => onOpenOrganization(announcement.organization.id)}>Open organization <Icon name="arrowRight" /></button>}
      {onDelete && canDelete?.(announcement) && (
        <button className="decline-button" disabled={loading} onClick={() => onDelete(announcement.organization.id, announcement.id)}>
          Delete announcement
        </button>
      )}
    </article>
  ))}</div>
}

export default App

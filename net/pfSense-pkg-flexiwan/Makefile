# $FreeBSD$

PORTNAME=	pfSense-pkg-flexiwan
PORTVERSION=	1.0.0
CATEGORIES=	net
MASTER_SITES=	# empty
DISTFILES=	# empty
EXTRACT_ONLY=	# empty

MAINTAINER=	your-email@example.com
COMMENT=	pfSense package FlexiWAN SD-WAN Integration

LICENSE=	MIT

NO_BUILD=	yes
NO_MTREE=	yes

SUB_FILES=	pkg-install pkg-deinstall
SUB_LIST=	PORTNAME=${PORTNAME}

do-extract:
	${MKDIR} ${WRKSRC}

do-install:
	${MKDIR} ${STAGEDIR}${PREFIX}/pkg
	${MKDIR} ${STAGEDIR}${PREFIX}/pkg/flexiwan/src
	${MKDIR} ${STAGEDIR}/usr/local/www
	${MKDIR} ${STAGEDIR}/usr/local/bin
	${MKDIR} ${STAGEDIR}/usr/local/etc/rc.d
	${MKDIR} ${STAGEDIR}${PREFIX}/share/pfSense-pkg-flexiwan
	
	# Install XML and INC files
	${INSTALL_DATA} ${FILESDIR}${PREFIX}/pkg/flexiwan.xml \
		${STAGEDIR}${PREFIX}/pkg
	${INSTALL_DATA} ${FILESDIR}${PREFIX}/pkg/flexiwan_device.xml \
		${STAGEDIR}${PREFIX}/pkg
	${INSTALL_DATA} ${FILESDIR}${PREFIX}/pkg/flexiwan.inc \
		${STAGEDIR}${PREFIX}/pkg
	${INSTALL_DATA} ${FILESDIR}${PREFIX}/pkg/flexiwan_device.inc \
		${STAGEDIR}${PREFIX}/pkg
		
	# Install PHP source files
	${INSTALL_DATA} ${FILESDIR}${PREFIX}/pkg/flexiwan/src/*.php \
		${STAGEDIR}${PREFIX}/pkg/flexiwan/src/
		
	# Install Web UI files
	${INSTALL_DATA} ${FILESDIR}/usr/local/www/*.php \
		${STAGEDIR}/usr/local/www/
		
	# Install Daemon scripts
	${INSTALL_SCRIPT} ${FILESDIR}/usr/local/bin/flexiwand \
		${STAGEDIR}/usr/local/bin/flexiwand
	${INSTALL_SCRIPT} ${FILESDIR}/usr/local/etc/rc.d/flexiwan.sh \
		${STAGEDIR}/usr/local/etc/rc.d/
		
	${INSTALL_DATA} ${FILESDIR}${PREFIX}/share/pfSense-pkg-flexiwan/info.xml \
		${STAGEDIR}${PREFIX}/share/pfSense-pkg-flexiwan/info.xml

.include <bsd.port.mk>
